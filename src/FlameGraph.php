<?php
/**
 * FlameGraph class.
 *
 * Generate an SVG flame graph from a list of stack samples.
 *
 * This is a PHP port of https://github.com/brendangregg/FlameGraph/blob/master/flamegraph.pl
 */

namespace FlameGraph;

use FlameGraph\SVG;
use Exception;

/**
 * Class FlameGraph
 */
class FlameGraph {

	/**
	 * Hash of merged frame data.
	 *
	 * @var array
	 */
	protected $node = array();

	/**
	 * Temp node.
	 *
	 * @var array
	 */
	protected $tmp = array();

	/**
	 * Args.
	 *
	 * @var array
	 */
	protected $args = array();

	/**
	 * FlameGraph constructor.
	 *
	 * @param array $args Args for flamegraph.
	 */
	protected function __construct( $args ) {
		$this->args = $args;
	}

	/**
	 * Build a flamegraph from a list of stack samples.
	 *
	 * @param array $samples List of stack samples in the format supported by flamegraph.pl.
	 * @param array $args    Optional args to control how flamegraph is generated. This
	 *                       is an associative array where the keys are options supported
	 *                       by flamegraph.pl and the values are their values.
	 *
	 * @return FlameGraph
	 *
	 * @throws Exception If an error is encountered.
	 */
	public static function build( $samples, $args = array() ) {
		if ( empty( $samples ) ) {
			throw new Exception( 'List of samples is required.' );
		}

		$args = self::parse_args( $args );

		$graph = new FlameGraph( $args );
		$graph->read_samples( $samples );

		return $graph;
	}

	protected static function parse_args( $args ) {
		$default_args = [
			'fonttype'   => 'Verdana',
			'width'      => 1200,
			'height'     => 16,
			'encoding'   => '',
			'fontsize'   => 12,
			'fontwidth'  => 0.59,
			'minwidth'   => 0.1,
			'title'      => '',
			'subtitle'   => '',
			'nametype'   => 'Function:',
			'countname'  => 'samples',
			'nameattr'   => array(),
			'total'      => '',
			'factor'     => 1,
			'colors'     => 'hot',
			'bgcolors'   => '',
			'hash'       => false,
			'cp'         => false,
			'reverse'    => false,
			'inverted'   => false,
			'flamechart' => false,
			'negate'     => false,
			'palette'    => false,
			'notes'      => '',
		];

		$args = array_merge( $default_args, $args );

		if ( ! $args['title'] ) {
			if ( $args['flamechart'] ) {
				$args['title'] = 'Flame Chart';
			} elseif ( $args['inverted'] ) {
				$args['title'] = 'Icicle Graph';
			} else {
				$args['title'] = 'Flame Graph';
			}
		}

		if ( $args['nameattr'] ) {
			$args['nameattr'] = self::read_name_attr( $args['nameattr'] );
		}

		if ( preg_match( '/[<>]/', $args['notes'] ) ) {
			throw new Exception( "Notes string can't contain < or >" );
		}

		if ( ! $args['bgcolors'] ) {
			$args['bgcolors'] = self::read_bg_colors( $args );
		}

		return $args;
	}

	protected static function read_name_attr( $filename ) {
		$fh = fopen( $filename, 'r' );

		if ( ! $fh ) {
			throw new Exception( "Can't read {$filename}" );
		}

		$name_attr = array();

		// The name-attribute file format is a function name followed by a tab then
		// a sequence of tab separated name=value pairs.
		while ( $line = fgets( $fh ) ) {
			$parts = explode( "\t", $line );

			if ( count( $parts ) < 2 ) {
				throw new Exception( "Invalid format in {$filename}" );
			}

			$funcname = $parts[0];
			$attrs    = array_slice( $parts, 1 );

			foreach ( $attrs as $attrstr ) {
				list( $name, $value ) = explode( '=', $attrstr );
				$name_attr[ $funcname ][ $name ] = $value;
			}
		}

		fclose( $fh );

		return $name_attr;
	}

	protected static function read_bg_colors( $args ) {
		/**
		 * Background colors:
		 * - yellow gradient: default (hot, java, js, perl)
		 * - green gradient: mem
		 * - blue gradient: io, wakeup, chain
		 * - gray gradient: flat colors (red, green, blue, ...)
		 */
		$bgcolors = $args['bgcolors'];

		if ( ! $args['bgcolors'] ) {
			$bgcolors = self::get_default_bg_colors( $args );
		}

		// color1 is gradient start, color2 is gradient end.
		if ( 'yellow' === $bgcolors ) {
			return array(
				'color1' => '#eeeeee',
				'color2' => '#eeeeb0'
			);
		} else if ( 'blue' === $bgcolors ) {
			return array(
				'color1' => '#eeeeee',
				'color2' => '#e0e0ff',
			);
		} else if ( 'green' === $bgcolors ) {
			return array(
				'color1' => '#eef2ee',
				'color2' => '#e0ffe0',
			);
		} else if ( 'grey' === $bgcolors ) {
			return array(
				'color1' => '#f8f8f8',
				'color2' => '#e8e8e8',
			);
		} elseif ( preg_match( "/^#......$/", $bgcolors ) ) {
			return array(
				'color1' => $bgcolors,
				'color2' => $bgcolors,
			);
		} else {
			throw new Exception( "Unrecognized bgcolor option '{$bgcolors}'" );
		}
	}

	protected static function get_default_bg_colors( $args ) {
		if ( 'mem' === $args['colors'] ) {
			return 'green';
		} else if ( preg_match( "/^(io|wakeup|chain)$/", $args['colors'] ) ) {
			return 'blue';
		} else if ( preg_match( "/^(red|green|blue|aqua|yellow|purple|orange)$/", $args['colors'] ) ) {
			return 'grey';
		} else {
			return 'yellow';
		}
	}

	/**
	 * Read samples and parse into node list.
	 *
	 * @param array $samples List of stack samples in format supported by flamegraph.pl.
	 *
	 * @throws Exception If input is invalid.
	 */
	protected function read_samples( $samples ) {
		$data = $samples;

		// Reverse if needed.
		if ( $this->args['reverse'] ) {
			foreach ( $data as $key => $sample ) {
				// There may be an extra samples column for differentials
				// XXX todo: redo these REs as one. It's repeated below.
				preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $sample, $matches );
				$stack     = $matches[1];
				$_samples  = $matches[2];
				$samples2  = null;
				if ( preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $stack ) ) {
					$samples2 = $_samples;

					preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $stack, $matches );

					$stack     = $matches[1];
					$_samples  = $matches[2];
					$new_stack = implode( ';', array_reverse( explode( ';', $stack ) ) );

					$data[ $key ] = "{$new_stack} {$_samples} {$samples2}";
				} else {
					$new_stack    = implode( ';', array_reverse( explode( ';', $stack ) ) );
					$data[ $key ] = "{$new_stack} {$_samples}";
				}
			}
		}

		if ( ! $this->args['flamechart'] ) {
			$data = array_reverse( $data );
		}

		$total_time = $this->process_frames( $data );

		if ( ! $total_time ) {
			throw new Exception( 'No valid input provided to FlameGraph.' );
		}

		if ( $this->args['total'] && $this->args['total'] < $total_time ) {
			// FlameGraph.pl would print a warning, we just silently continue.
			if ( $this->args['total'] / $total_time > 0.02 ) {
				unset( $this->args['total'] );
			}
		}

		if ( empty( $this->args['total'] ) ) {
			$this->args['total'] = $total_time;
		}
	}

	/**
	 * Process and merge frames to build node list.
	 *
	 * @param array $sorted_data
	 *
	 * @return float Total time
	 */
	protected function process_frames( $sorted_data ) {
		$this->node = array();

		$last     = array();
		$maxdelta = 1;
		$delta    = null;
		$time     = 0;

		foreach ( $sorted_data as $line ) {
			preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $line, $matches );

			if ( empty( $matches[1] ) || empty( $matches[2] ) ) {
				continue;
			}

			$stack   = $matches[1];
			$samples = $matches[2];

			// There may be an extra samples column for differentials:
			$samples2 = null;
			if ( preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $stack ) ) {
				$samples2 = $samples;
				preg_match( "/^(.*)\s+?(\d+(?:\.\d*)?)$/", $stack, $matches );
				$stack   = $matches[1];
				$samples = $matches[2];
			}
			$delta = null;
			if ( $samples2 ) {
				$delta = $samples2 - $samples;
				if ( abs( $delta ) > $maxdelta ) {
					$maxdelta = abs( $delta );
				}
			}

			// For chain graphs, annotate waker frames with "_[w]", for later
			// coloring. This is a hack, but has a precedent ("_[k]" from perf).
			if ( 'chain' === $this->args['colors'] ) {
				$parts    = explode( ';--;', $stack );
				$newparts = array();
				$stack    = array_shift( $parts );
				$stack   .= ";--;";
				foreach ( $parts as $part ) {
					$part        = str_replace( ';', '_[w];', $part );
					$part       .= '_[w]';
					$new_parts[] = $part;
				}
				$stack .= implode( ';--;', $parts );
			}

			// Merge frames and populate $this->node.
			$last = $this->flow(
				$last,
				array_merge( array( '' ), explode( ';', $stack ) ),
				$time,
				$delta
			);

			if ( $samples2 ) {
				$time += $samples2;
			} else {
				$time += $samples;
			}
		}

		return $time;
	}

	/**
	 * Merge two stacks and store the merged frames and value data in the node list.
	 *
	 * @param array $last
	 * @param array $cur
	 * @param float $v
	 * @param int   $d
	 */
	protected function flow( $last, $cur, $v, $d = null ) {
		$len_a = count( $last ) - 1;
		$len_b = count( $cur ) - 1;

		$i = 0;
		$len_same;
		for ( ; $i <= $len_a; $i++ ) {
			if ( $i > $len_b ) {
				break;
			}
			if ( $last[ $i ] !== $cur[ $i ] ) {
				break;
			}
		}
		$len_same = $i;

		for ( $i = $len_a; $i >= $len_same; $i-- ) {
			$k = "{$last[$i]};{$i}";
			// A unique ID is constructed from "func;depth;etime";
			// func-depth isn't unique, it may be repeated later.
			$this->node["{$k};{$v}"]['stime'] = $this->tmp[ $k ]['stime'];
			if ( isset( $this->tmp[ $k ]['delta'] ) ) {
				$this->node["{$k};{$v}"]['delta'] = $this->tmp[ $k ]['delta'];
			}
			unset( $this->tmp[ $k] );
		}

		for ( $i = $len_same; $i <= $len_b; $i++ ) {
			$k = "{$cur[$i]};{$i}";
			$this->tmp[ $k ]['stime'] = $v;
			if ( $d ) {
				$this->tmp[ $k ]['delta'] += $i === $len_b ? $d : 0;
			}
		}

		return $cur;
	}

	/**
	 * Get the FlameGraph as an SVG.
	 *
	 * @return FlameGraph\SVG
	 */
	public function to_svg() {
		$minwidth     = $this->args['minwidth'];
		$fontsize     = $this->args['fontsize'];
		$timemax      = $this->args['total'];
		$fonttype     = $this->args['fonttype'];
		$fontwidth    = $this->args['fontwidth'];
		$bgcolor1     = $this->args['bgcolors']['color1'];
		$bgcolor2     = $this->args['bgcolors']['color2'];
		$nametype     = $this->args['nametype'];
		$inverted     = (int) $this->args['inverted'];
		$titletext    = $this->args['title'];
		$subtitletext = $this->args['subtitle'];
		$factor       = $this->args['factor'];
		$nameattr     = $this->args['nameattr'];
		$negate       = $this->args['negate'];
		$palette      = $this->args['palette'];
		$hash         = $this->args['hash'];
		$colors       = $this->args['colors'];
		$frameheight  = $this->args['height'];
		$notes        = $this->args['notes'];
		$encoding     = $this->args['encoding'];
		$countname    = $this->args['countname'];

		$ypad1         = $fontsize * 3;      // pad top, include title
		$ypad2         = $fontsize * 2 + 10; // pad bottom, include labels
		$ypad3         = $fontsize * 2;      // pad top, include subtitle (optional)
		$xpad          = 10;                 // pad left and right
		$framepad      = 1;		             // vertical padding for frames
		$depthmax      = 0;
		$imagewidth    = $this->args['width'];
		$widthpertime  = ( $imagewidth - 2 * $xpad ) / $timemax;
		$minwidth_time = $minwidth / $widthpertime;

		// Prune blocks that are too narrow and determine max depth.
		foreach ( $this->node as $id => $node ) {
			list ( $func, $depth, $etime ) = explode( ';', $id );
			if ( ! isset( $node['stime'] ) ) {
				throw new Exception( "missing start for {$id}" );
			}
			$stime = $node['stime'];

			if ( ( $etime - $stime ) < $minwidth_time ) {
				unset( $this->node[ $id ] );
				continue;
			}
			if ( $depth > $depthmax ) {
				$depthmax = $depth;
			}
		}

		$imageheight = ( ( $depthmax + 1 ) * $frameheight ) + $ypad1 + $ypad2;

		if ( $subtitletext ) {
			$imageheight += $ypad3;
		}

		$im = new SVG();
		$im->header( $imagewidth, $imageheight, $notes, $encoding );
		
		$black     = $this->color_allocate( 0, 0, 0 );
		$vdgrey    = $this->color_allocate( 160, 160, 160 );
		$dgrey     = $this->color_allocate( 200, 200, 200 );
		$titlesize = $fontsize + 5;

		$inc = <<<INC
<defs>
	<linearGradient id="background" y1="0" y2="1" x1="0" x2="0" >
		<stop stop-color="$bgcolor1" offset="5%" />
		<stop stop-color="$bgcolor2" offset="95%" />
	</linearGradient>
</defs>
<style type="text/css">
	text { font-family:$fonttype; font-size:${fontsize}px; fill:$black; }
	#search, #ignorecase { opacity:0.1; cursor:pointer; }
	#search:hover, #search.show, #ignorecase:hover, #ignorecase.show { opacity:1; }
	#subtitle { text-anchor:middle; font-color:$vdgrey; }
	#title { text-anchor:middle; font-size:${titlesize}px}
	#unzoom { cursor:pointer; }
	#frames > *:hover { stroke:black; stroke-width:0.5; cursor:pointer; }
	.hide { display:none; }
	.parent { opacity:0.5; }
</style>
<script type="text/ecmascript">
<![CDATA[
	"use strict";
	var details, searchbtn, unzoombtn, matchedtxt, svg, searching, currentSearchTerm, ignorecase, ignorecaseBtn;
	function init(evt) {
		details = document.getElementById("details").firstChild;
		searchbtn = document.getElementById("search");
		ignorecaseBtn = document.getElementById("ignorecase");
		unzoombtn = document.getElementById("unzoom");
		matchedtxt = document.getElementById("matched");
		svg = document.getElementsByTagName("svg")[0];
		searching = 0;
		currentSearchTerm = null;
	}
	window.addEventListener("click", function(e) {
		var target = find_group(e.target);
		if (target) {
			if (target.nodeName == "a") {
				if (e.ctrlKey === false) return;
				e.preventDefault();
			}
			if (target.classList.contains("parent")) unzoom();
			zoom(target);
		}
		else if (e.target.id == "unzoom") unzoom();
		else if (e.target.id == "search") search_prompt();
		else if (e.target.id == "ignorecase") toggle_ignorecase();
	}, false)
	// mouse-over for info
	// show
	window.addEventListener("mouseover", function(e) {
		var target = find_group(e.target);
		if (target) details.nodeValue = "$nametype " + g_to_text(target);
	}, false)
	// clear
	window.addEventListener("mouseout", function(e) {
		var target = find_group(e.target);
		if (target) details.nodeValue = ' ';
	}, false)
	// ctrl-F for search
	window.addEventListener("keydown",function (e) {
		if (e.keyCode === 114 || (e.ctrlKey && e.keyCode === 70)) {
			e.preventDefault();
			search_prompt();
		}
	}, false)
	// ctrl-I to toggle case-sensitive search
	window.addEventListener("keydown",function (e) {
		if (e.ctrlKey && e.keyCode === 73) {
			e.preventDefault();
			toggle_ignorecase();
		}
	}, false)
	// functions
	function find_child(node, selector) {
		var children = node.querySelectorAll(selector);
		if (children.length) return children[0];
		return;
	}
	function find_group(node) {
		var parent = node.parentElement;
		if (!parent) return;
		if (parent.id == "frames") return node;
		return find_group(parent);
	}
	function orig_save(e, attr, val) {
		if (e.attributes["_orig_" + attr] != undefined) return;
		if (e.attributes[attr] == undefined) return;
		if (val == undefined) val = e.attributes[attr].value;
		e.setAttribute("_orig_" + attr, val);
	}
	function orig_load(e, attr) {
		if (e.attributes["_orig_"+attr] == undefined) return;
		e.attributes[attr].value = e.attributes["_orig_" + attr].value;
		e.removeAttribute("_orig_"+attr);
	}
	function g_to_text(e) {
		var text = find_child(e, "title").firstChild.nodeValue;
		return (text)
	}
	function g_to_func(e) {
		var func = g_to_text(e);
		// if there's any manipulation we want to do to the function
		// name before it's searched, do it here before returning.
		return (func);
	}
	function update_text(e) {
		var r = find_child(e, "rect");
		var t = find_child(e, "text");
		var w = parseFloat(r.attributes.width.value) -3;
		var txt = find_child(e, "title").textContent.replace(/\\([^(]*\\)\$/,"");
		t.attributes.x.value = parseFloat(r.attributes.x.value) + 3;
		// Smaller than this size won't fit anything
		if (w < 2 * $fontsize * $fontwidth) {
			t.textContent = "";
			return;
		}
		t.textContent = txt;
		// Fit in full text width
		if (/^ *\$/.test(txt) || t.getSubStringLength(0, txt.length) < w)
			return;
		for (var x = txt.length - 2; x > 0; x--) {
			if (t.getSubStringLength(0, x + 2) <= w) {
				t.textContent = txt.substring(0, x) + "..";
				return;
			}
		}
		t.textContent = "";
	}
	// zoom
	function zoom_reset(e) {
		if (e.attributes != undefined) {
			orig_load(e, "x");
			orig_load(e, "width");
		}
		if (e.childNodes == undefined) return;
		for (var i = 0, c = e.childNodes; i < c.length; i++) {
			zoom_reset(c[i]);
		}
	}
	function zoom_child(e, x, ratio) {
		if (e.attributes != undefined) {
			if (e.attributes.x != undefined) {
				orig_save(e, "x");
				e.attributes.x.value = (parseFloat(e.attributes.x.value) - x - $xpad) * ratio + $xpad;
				if (e.tagName == "text")
					e.attributes.x.value = find_child(e.parentNode, "rect[x]").attributes.x.value + 3;
			}
			if (e.attributes.width != undefined) {
				orig_save(e, "width");
				e.attributes.width.value = parseFloat(e.attributes.width.value) * ratio;
			}
		}
		if (e.childNodes == undefined) return;
		for (var i = 0, c = e.childNodes; i < c.length; i++) {
			zoom_child(c[i], x - $xpad, ratio);
		}
	}
	function zoom_parent(e) {
		if (e.attributes) {
			if (e.attributes.x != undefined) {
				orig_save(e, "x");
				e.attributes.x.value = $xpad;
			}
			if (e.attributes.width != undefined) {
				orig_save(e, "width");
				e.attributes.width.value = parseInt(svg.width.baseVal.value) - ($xpad * 2);
			}
		}
		if (e.childNodes == undefined) return;
		for (var i = 0, c = e.childNodes; i < c.length; i++) {
			zoom_parent(c[i]);
		}
	}
	function zoom(node) {
		var attr = find_child(node, "rect").attributes;
		var width = parseFloat(attr.width.value);
		var xmin = parseFloat(attr.x.value);
		var xmax = parseFloat(xmin + width);
		var ymin = parseFloat(attr.y.value);
		var ratio = (svg.width.baseVal.value - 2 * $xpad) / width;
		// XXX: Workaround for JavaScript float issues (fix me)
		var fudge = 0.0001;
		unzoombtn.classList.remove("hide");
		var el = document.getElementById("frames").children;
		for (var i = 0; i < el.length; i++) {
			var e = el[i];
			var a = find_child(e, "rect").attributes;
			var ex = parseFloat(a.x.value);
			var ew = parseFloat(a.width.value);
			var upstack;
			// Is it an ancestor
			if ($inverted == 0) {
				upstack = parseFloat(a.y.value) > ymin;
			} else {
				upstack = parseFloat(a.y.value) < ymin;
			}
			if (upstack) {
				// Direct ancestor
				if (ex <= xmin && (ex+ew+fudge) >= xmax) {
					e.classList.add("parent");
					zoom_parent(e);
					update_text(e);
				}
				// not in current path
				else
					e.classList.add("hide");
			}
			// Children maybe
			else {
				// no common path
				if (ex < xmin || ex + fudge >= xmax) {
					e.classList.add("hide");
				}
				else {
					zoom_child(e, xmin, ratio);
					update_text(e);
				}
			}
		}
		search();
	}
	function unzoom() {
		unzoombtn.classList.add("hide");
		var el = document.getElementById("frames").children;
		for(var i = 0; i < el.length; i++) {
			el[i].classList.remove("parent");
			el[i].classList.remove("hide");
			zoom_reset(el[i]);
			update_text(el[i]);
		}
		search();
	}
	// search
	function toggle_ignorecase() {
		ignorecase = !ignorecase;
		if (ignorecase) {
			ignorecaseBtn.classList.add("show");
		} else {
			ignorecaseBtn.classList.remove("show");
		}
		reset_search();
		search();
	}
	function reset_search() {
		var el = document.querySelectorAll("#frames rect");
		for (var i = 0; i < el.length; i++) {
			orig_load(el[i], "fill")
		}
	}
	function search_prompt() {
		if (!searching) {
			var term = prompt("Enter a search term (regexp " +
			    "allowed, eg: ^ext4_)"
			    + (ignorecase ? ", ignoring case" : "")
			    + "\\nPress Ctrl-i to toggle case sensitivity", "");
			if (term != null) {
				currentSearchTerm = term;
				search();
			}
		} else {
			reset_search();
			searching = 0;
			currentSearchTerm = null;
			searchbtn.classList.remove("show");
			searchbtn.firstChild.nodeValue = "Search"
			matchedtxt.classList.add("hide");
			matchedtxt.firstChild.nodeValue = ""
		}
	}
	function search(term) {
		if (currentSearchTerm === null) return;
		var term = currentSearchTerm;
		var re = new RegExp(term, ignorecase ? 'i' : '');
		var el = document.getElementById("frames").children;
		var matches = new Object();
		var maxwidth = 0;
		for (var i = 0; i < el.length; i++) {
			var e = el[i];
			var func = g_to_func(e);
			var rect = find_child(e, "rect");
			if (func == null || rect == null)
				continue;
			// Save max width. Only works as we have a root frame
			var w = parseFloat(rect.attributes.width.value);
			if (w > maxwidth)
				maxwidth = w;
			if (func.match(re)) {
				// highlight
				var x = parseFloat(rect.attributes.x.value);
				orig_save(rect, "fill");
				rect.attributes.fill.value = "rgb(230,0,230)";
				// remember matches
				if (matches[x] == undefined) {
					matches[x] = w;
				} else {
					if (w > matches[x]) {
						// overwrite with parent
						matches[x] = w;
					}
				}
				searching = 1;
			}
		}
		if (!searching)
			return;
		searchbtn.classList.add("show");
		searchbtn.firstChild.nodeValue = "Reset Search";
		// calculate percent matched, excluding vertical overlap
		var count = 0;
		var lastx = -1;
		var lastw = 0;
		var keys = Array();
		for (k in matches) {
			if (matches.hasOwnProperty(k))
				keys.push(k);
		}
		// sort the matched frames by their x location
		// ascending, then width descending
		keys.sort(function(a, b){
			return a - b;
		});
		// Step through frames saving only the biggest bottom-up frames
		// thanks to the sort order. This relies on the tree property
		// where children are always smaller than their parents.
		var fudge = 0.0001;	// JavaScript floating point
		for (var k in keys) {
			var x = parseFloat(keys[k]);
			var w = matches[keys[k]];
			if (x >= lastx + lastw - fudge) {
				count += w;
				lastx = x;
				lastw = w;
			}
		}
		// display matched percent
		matchedtxt.classList.remove("hide");
		var pct = 100 * count / maxwidth;
		if (pct != 100) pct = pct.toFixed(1)
		matchedtxt.firstChild.nodeValue = "Matched: " + pct + "%";
	}
]]>
</script>
INC;

		$im->include( $inc );
		$im->filled_rectangle( 0, 0, $imagewidth, $imageheight, 'url(#background)' );
		$im->string_ttf( "title", intval( $imagewidth / 2 ), $fontsize * 2, $titletext );
		if ( $subtitletext ) {
			$im->string_ttf( "subtitle", intval( $imagewidth / 2 ), $fontsize * 4, $subtitletext );
		}
		$im->string_ttf( "details", $xpad, $imageheight - ( $ypad2 / 2 ), " " );
		$im->string_ttf( "unzoom", $xpad, $fontsize * 2, "Reset Zoom", 'class="hide"' );
		$im->string_ttf( "search", $imagewidth - $xpad - 100, $fontsize * 2, "Search" );
		$im->string_ttf( "ignorecase", $imagewidth - $xpad - 16, $fontsize * 2, "ic" );
		$im->string_ttf( "matched", $imagewidth - $xpad - 100, $imageheight - ( $ypad2 / 2 ), " " );

		$palette_map = array();

		if ( $palette ) {
			$palette_map = $this->read_palette();
		}

		$im->group_start( array( 'id' => 'frames' ) );

		foreach ( $this->node as $id => $node ) {
			list ( $func, $depth, $etime ) = explode( ';', $id );
			$stime = $node['stime'];
			$delta = isset( $node['delta'] ) ? $node['delta'] : 0;

			if ( '' === $func && 0 === $depth ) {
				$etime = $timemax;
			}

			$x1 = $xpad + $stime * $widthpertime;
			$x2 = $xpad + $etime * $widthpertime;

			if ( $inverted ) {
				$y1 = $ypad1 + $depth * $frameheight;
				$y2 = $ypad1 + ( $depth + 1 ) * $frameheight - $framepad;
			} else {
				$y1 = $imageheight - $ypad2 - ( $depth + 1 ) * $frameheight + $framepad;
				$y2 = $imageheight - $ypad2 - $depth * $frameheight;
			}

			$samples     = sprintf( "%.0f", ( $etime - $stime ) * $factor );
			$samples_txt = preg_replace( // add commas per perlfaq5
				"/(^[-+]?\d+?(?=(?>(?:\d{3})+)(?!\d))|\G\d{3}(?=\d))/",
				"$1,",
				$samples
			);

			$info;
			if ( '' === $func && 0 === $depth ) {
				$info = "all ($samples_txt $countname, 100%)";
			} else {
				$pct          = sprintf( "%.2f", ( ( 100 * $samples ) / ( $timemax * $factor ) ) );
				$escaped_func = $func;
				// Clean up SVG breaking characters:
				$bad_chars = array(
					'&' => '&amp;',
					'<' => '&lt;',
					'>' => '&gt;',
					'"' => '&quot;',
				);
				$escaped_func = str_replace(
					array_keys( $bad_chars ),
					array_values( $bad_chars ),
					$escaped_func
				);
				// strip any annotation.
				$escaped_func = preg_replace( "/_\[[kwij]\]$/", '', $escaped_func );
				if ( $delta ) {
					$d        = $negate ? -$delta : $delta;
					$deltapct = sprintf( "%.2f", ( ( 100 * $d ) / ( $timemax * $factor ) ) );
					$deltapct = $d > 0 ? "+$deltapct" : $deltapct;
					$info     = "$escaped_func ($samples_txt $countname, $pct%; $deltapct%)";
				} else {
					$info = "$escaped_func ($samples_txt $countname, $pct%)";
				}
			}

			$nameattr = isset( $name_attr[ $func ] ) ? $nameattr[ $func ] : array();	

			if ( empty( $nameattr['title'] ) ) {
				$nameattr['title'] = $info;
			}

			$im->group_start( $nameattr );

			$color;
			if ( '--' === $func ) {
				$color = $vdgrey;
			} else if ( '-' === $func ) {
				$color = $dgrey;
			} else if ( $delta ) {
				$color = $this->color_scale( $delta, $maxdelta );
			} else if ( $palette ) {
				$color = $this->color_map( $colors, $func, $palette_map );
			} else {
				$color = $this->color( $colors, $func );
			}

			$im->filled_rectangle( $x1, $y1, $x2, $y2, $color, 'rx="2" ry="2"' );

			$chars = intval( ( $x2 - $x1 ) / ( $fontsize * $fontwidth ) );
			$text  = '';
			if ( $chars >= 3 ) { // room for one char plus two dots
				$func = preg_replace( "/_\[[kwij]\]$/", '', $func ); // strip any annotation
				$text = substr( $func, 0, $chars );
				if ( $chars < strlen( $func ) ) {
					$text  = substr( $text, 0, $chars - 2 );
					$text .= '..';
				}
				$text = str_replace( '&', '&amp;', $text );
				$text = str_replace( '<', '&lt;', $text );
				$text = str_replace( '>', '&gt;', $text );
			}

			$im->string_ttf( null, $x1 + 3, 3 + ( $y1 + $y2 ) / 2, $text );
			$im->group_end( $nameattr );
		}

		$im->group_end();

		if ( $palette ) {
			$this->write_palette( $palette_map );
		}

		return $im;
	}

	/**
	 * Get color.
	 *
	 * @param string $type
	 * @param string $name
	 *
	 * @return string
	 */
	protected function color( $type, $name ) {
		if ( $this->args['hash'] ) {
			$v1 = $this->name_hash( $name );
			$v2 = $v2 = $this->name_hash( strrev( $name ) );
		} else {
			$v1 = rand( 0, 1 );
			$v2 = rand( 0, 1 );
			$v3 = rand( 0, 1 );
		}

		// Theme palettes.
		if ( 'hot' === $type ) {
			$r = 205 + intval( 50 * $v3 );
			$g = 0 + intval( 230 * $v1 );
			$b = 0 + intval( 55 * $v2 );

			return "rgb({$r},{$g},{$b})";
		} else if ( 'mem' === $type ) {
			$r = 0;
			$g = 190 + intval( 50 * $v2 );
			$b = 0 + intval( 210 * $v1 );
			
			return "rgb({$r},{$g},{$b})";
		} else if ( 'io' === $type ) {
			$r = 80 + intval( 60 * $v1 );
			$g = $r;
			$b = 190 + intval( 55 * $v2 );

			return "rgb({$r},{$g},{$b})";
		}

		// Multi palettes.
		if ( 'java' === $type ) {
			/**
			 * Handle both annotations (_[j], _[i], ...; which are
			 * accurate), as well as input that lacks any annotations, as
			 * best as possible. Without annotations, we get a little hacky
			 * and match on java|org|com, etc.
			 */
			if ( preg_match( "/_\[j\]/", $name ) ) {  // jit annotation
				$type = 'green';
			} else if ( preg_match( "/_\[i\]/", $name ) ) {  // inline annotation
				$type = 'aqua';
			} else if ( preg_match( "/^L?(java|javax|jdk|net|org|com|io|sun)/", $name ) ) {  // Java
				$type = 'green';
			} else if ( preg_match( "/_\[k\]/", $name ) ) {  // kernel annotation
				$type = 'orange';
			} else if ( preg_match( "/::/", $name ) ) { // C++
				$type = 'yellow';
			} else {  // System
				$type = 'red';
			}
		}

		if ( 'perl' === $type ) {
			if ( preg_match( "/::/", $name ) ) { // C++
				$type = 'yellow';
			} else if ( preg_match( "/(Perl|\.pl)/", $name ) ) { // Perl
				$type = 'green';
			} else if ( preg_match( "/_\[k\]$/", $name ) ) { // kernel
				$type = 'orange';
			} else { // system
				$type = 'red';
			}
		}

		if ( 'js' === $type ) {
			/**
			 * Handle both annotations (_[j], _[i], ...; which are
			 * accurate), as well as input that lacks any annotations, as
			 * best as possible. Without annotations, we get a little hacky
			 * and match on a "/" with a ".js", etc.
			 */
			if ( preg_match( "/_\[j\]$/", $name ) ) { // jit annotation
				if ( false !== strpos( $name, '/' ) ) {
					$type = 'green'; // source
				} else {
					$type = 'aqua'; // builtin
				}
			} else if ( preg_match( "/::/", $name ) ) { // C++
				$type = 'yellow';
			} else if ( preg_match( "/\/.*\.js/", $name ) ) { // JavaScript (match "/" in path)
				$type = 'green';
			} else if ( false !== strpos( $name, ':' ) ) { // JavaScript (match ":" in builtin)
				$type = 'aqua';
			} else if ( preg_match( "/^ $/", $name ) ) { // Missing symbol
				$type = 'green';
			} else if ( preg_match( "/_\[k\]/", $name ) ) { // kernel
				$type = 'orange';
			} else { // system
				$type = 'red';
			}
		}

		if ( 'wakeup' === $type ) {
			$type = 'aqua';
		}

		if ( 'chain' === $type ) {
			if ( preg_match( "/_\[w\]/", $name ) ) { // waker
				$type = 'aqua';
			} else { // off-CPU
				$type = 'blue';
			}
		}

		// Color palettes.
		switch ( $type ) {
			case 'red':
				$r = 200 + intval( 55 * $v1 );
				$g = $b = 50 + intval( 80 * $v1 );
				break;
			case 'green':
				$g = 200 + intval( 55 * $v1 );
				$r = $b = 50 + intval( 60 * $v1 );
				break;
			case 'blue':
				$b = 205 + intval( 50 * $v1 );
				$r = $g = 80 + intval( 60 * $v1 );
				break;
			case 'yellow':
				$b = 50 + intval( 20 * $v1 );
				$r = $g = 175 + intval( 55 * $v1 );
				break;
			case 'purple':
				$g = 80 + intval( 60 * $v1 );
				$r = $b = 190 + intval( 65 * $v1 );
				break;
			case 'aqua':
				$r = 50 + intval( 60 * $v1 );
				$g = $b = 165 + intval( 55 * $v1 );
				break;
			case 'orange':
				$r = 190 + intval( 65 * $v1 );
				$g = 90 + int( 65 * $v1 );
				$b = 0;
				break;
			default:
				$r = $g = $b = 0;
				break;
		}

		return "rgb({$r},{$g},{$b})";
	}

	/**
	 * Generate a vector hash for the name string, weighting early over
	 * later characters. We want to pick the same colors for function
	 * names across different flame graphs.
	 *
	 * @param string $name
	 *
	 * @return float
	 */
	protected function name_hash( $name ) {
		$vector = 0;
		$weight = 1;
		$max    = 1;
		$mod    = 10;

		// If module name present, trunc to 1st char.
		$name = preg_replace( "/.(.*?)`/", '', $name );

		foreach ( str_split( $name ) as $c ) {
			$i       = ord( $c ) % $mod;
			$vector += ($i / ($mod++ - 1)) * $weight;
			$max    += 1 * $weight;
			$weight *= 0.70;
			if ( $mod > 12 ) {
				break;
			}
		}

		return ( 1 - $vector / $max );
	}

	/**
	 * Scale color.
	 *
	 * @param float $value
	 * @param float $max
	 *
	 * @return string
	 */
	protected function color_scale( $value, $max ) {
		$r = $g = $b = 255;
		if ( $this->args['negate'] ) {
			$value = $value * -1;
		}
		if ( $value > 0 ) {
			$g = $b = intval( 210 * ( $max - $value ) / $max );
		} else if ( $value < 0 ) {
			$r = $g = intval( 210 * ( $max + $value ) / $max );
		}
		return "rgb({$r},{$g},{$b})";
	}

	/**
	 * Return rgb string for color.
	 *
	 * @param int $r
	 * @param int $g
	 * @param int $b
	 */
	protected function color_allocate( $r, $g, $b ) {
		return "rgb({$r},{$g},{$b})";
	}

	/**
	 * Get color for function from map.
	 *
	 * @param array  $colors
	 * @param string $func
	 * @param array  $palette
	 *
	 * @return string
	 */
	protected function color_map( $colors, $func, &$palette ) {
		if ( ! array_key_exists( $func, $palette ) ) {
			$palette[ $func ] = $this->color( $colors, $func );
		}

		return $palette[ $func ];
	}

	/**
	 * Write palette to file.
	 *
	 * @param array $palette_map
	 */
	protected function write_palette( $palette_map ) {
		$fh = fopen( 'palette.map', 'w' );

		if ( ! $fh ) {
			throw new Exception( "Can't write to file palette.map" );
		}

		$keys = array_keys( $palette_map );
		sort( $keys );

		foreach ( $keys as $key ) {
			fwrite( $fh, sprintf( "%s->%s\n", $key, $palette_map[ $key ] ) );
		}

		fclose( $fh );
	}

	/**
	 * Read palette from file.
	 *
	 * @return array
	 */
	protected function read_palette() {
		$fh = fopen( 'palette.map', 'r' );

		if ( ! $fh ) {
			throw new Exception( "Can't open file palette.map" );
		}

		$palette_map = array();

		while ( $line = fgets( $fh ) ) {
			list( $key, $value ) = explode( '->', $line );
			$palette_map[ $key ] = $value;
		}

		fclose( $fh );

		return $palette_map;
	}

}

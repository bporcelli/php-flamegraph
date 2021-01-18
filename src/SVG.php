<?php
/**
 * SVG class.
 *
 * Ported from https://github.com/brendangregg/FlameGraph/blob/master/flamegraph.pl#L261
 */

namespace FlameGraph;

/**
 * Class SVG
 */
class SVG {

	/**
	 * Generated SVG markup.
	 *
	 * @var string
	 */
	public $svg = '';

	/**
	 * Print the SVG header.
	 *
	 * @param float  $w
	 * @param float  $h
	 * @param string $notes 
	 * @param string $encoding
	 */
	public function header( $w, $h, $notes, $encoding ) {
		$enc_attr = '';

		if ( $encoding ) {
			$enc_attr = ' encoding="' . $encoding . '"';
		}

		$this->svg .= <<<SVG
<?xml version="1.0"{$enc_attr} standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg version="1.1" width="{$w}" height="{$h}" onload="init(evt)" viewBox="0 0 {$w} {$h}" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
<!-- Flame graph stack visualization. See https://github.com/brendangregg/FlameGraph for latest version, and http://www.brendangregg.com/flamegraphs.html for examples. -->
<!-- NOTES: {$notes} -->
SVG;
	}

	/**
	 * Add content to SVG.
	 *
	 * @param string $content
	 */
	public function include( $content ) {
		$this->svg .= $content;
	}

	/**
	 * Generate RGB string.
	 *
	 * @param int $r
	 * @param int $g
	 * @param int $b
	 *
	 * @return string
	 */
	public function color_allocate( $r, $g, $b ) {
		return "rgb({$r},{$g},{$b}";
	}

	/**
	 * Open SVG group.
	 *
	 * @param array $attr
	 */
	public function group_start( $attr ) {
		$g_attr = array();

		foreach ( array( 'id', 'class' ) as $attr_name ) {
			if ( isset( $attr[ $attr_name ] ) ) {
				$g_attr[] = sprintf( '%s="%s"', $attr_name, $attr[ $attr_name ] );
			}
		}

		if ( isset( $attr['g_extra'] ) ) {
			$g_attr[] = $attr['g_extra'];
		}

		if ( isset( $attr['href'] ) ) {
			// Default target=_top else links will open within SVG <object>.
			$target = $attr['target'] ? $attr['target'] : '_top';
			$a_attr = array(
				'xlink:href="' . $attr['href'] . '"',
				'target="' . $target . '"',
			);

			if ( isset( $attr['a_extra'] ) ) {
				$a_attr[] = $attr['a_extra'];
			}

			$this->svg .= sprintf( "<a %>\n", implode( ' ', array_merge( $a_attr, $g_attr ) ) );
		} else {
			$this->svg .= sprintf( "<g %s>\n", implode( ' ', $g_attr ) );
		}

		if ( isset( $attr['title'] ) ) {
			// Should be first element within g container.
			$this->svg .= sprintf( '<title>%s</title>', $attr['title'] );
		}
	}

	/**
	 * Close SVG group.
	 *
	 * @param array $attr
	 */
	public function group_end( $attr = array() ) {
		$this->svg .= ! empty( $attr['href'] ) ? "</a>\n" : "</g>\n";
	}

	/**
	 * Generate a filled rectangle.
	 *
	 * @param float  $x1
	 * @param float  $y1
	 * @param float  $x2
	 * @param float  $y2
	 * @param string $fill
	 * @param string $extra
	 */
	public function filled_rectangle( $x1, $y1, $x2, $y2, $fill, $extra = '' ) {
		$width  = sprintf( '%0.1f', $x2 - $x1 );
		$height = sprintf( '%0.1f', $y2 - $y1 );

		$this->svg .= sprintf(
			"<rect x=\"%0.1f\" y=\"%0.1f\" width=\"%0.1f\" height=\"%0.1f\" fill=\"%s\" %s />\n",
			$x1,
			$y1,
			$width,
			$height,
			$fill,
			$extra
		);
	}

	/**
	 * Print text.
	 *
	 * @param string $id
	 * @param float  $x
	 * @param float  $y
	 * @param string $str
	 * @param string $extra
	 */
	public function string_ttf( $id, $x, $y, $str, $extra = '' ) {
		if ( $id ) {
			$id = "id=\"{$id}\"";
		}

		$this->svg .= sprintf(
			'<text %s x="%0.2f" y="%f" %s>%s</text>',
			$id,
			$x,
			$y,
			$extra,
			$str
		);
	}

	/**
	 * Get SVG markup.
	 *
	 * @return string
	 */
	public function get() {
		return "{$this->svg}</svg>\n";
	}

	/**
	 * Save to file.
	 *
	 * @param string $filename
	 *
	 * @throws Exception If write fails.
	 */
	public function save( $filename ) {
		$fh = fopen( $filename, 'w' );

		if ( ! $fh ) {
			throw new Exception( "Can't write SVG to {$filename}" );
		}

		fwrite( $fh, $this->get() );
		fclose( $fh );
	}

}

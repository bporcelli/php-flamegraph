# PHP FlameGraph

This is a PHP port of https://github.com/brendangregg/FlameGraph/blob/master/flamegraph.pl.

**Why?**

I built it to generate the FlameGraphs for my fork of the Query Monitor Flamegraphs extension. The D3.js library the original extension used was crashing my browser, so I wanted to try generating the flamegraph server side instead. I also didn't want to introduce any external dependencies to do that.

**Does it work?**

In my tests it seems to work with the basic options supported by FlameGraph, but it's definitely not production ready and I'd advise against using it for anything mission critical.

**How do I use it?**

See `test.php` for basic usage examples.

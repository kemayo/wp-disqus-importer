=== Disqus Comments Importer ===
Contributors: automattic, blepoxp, David Lynch
Tags: comments, disqus, import
Requires at least: 4.0
Tested up to: 4.4
Stable tag: 0.1

Import comments from a Disqus export file.

== Description ==

This plugin will import comments from a Disqus export file. You need to get one of those from Disqus first.

Due to a limitation with the current version of the Disqus Comments plugin the import does not retain nested commenting. Once this support is available in the XML, we will provide support.

Please provide feedback and bug reports in the [Forums](http://wordpress.org/tags/disqus-comments-importer?forum_id=10#postform)

== Installation ==

Upload the Disqus Comments Importer plugin to your blog, Activate it, then navigate to Tools -> Imports from your WordPress admin panel.

== Changelog ==

= 0.2 =

* Fix to support the newer-better Disqus XML dump
* Use SimpleXML to parse the file, rather than line-by-line regular expressions

= 0.1 =

* Initial development. Looking for feedback.
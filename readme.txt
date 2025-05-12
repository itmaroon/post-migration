=== POST MIGRATION ===
Contributors: itmaroon
Tags: post, duplicate, transfer, media
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 0.1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 8.2

This is a plugin that transfers data for individual posts to another WordPress site.

== Description ==
This plugin provides the ability to migrate individual WordPress post data to another WordPress site.
Specifically, it provides the following features that are not provided by the standard import and export tools provided by WordPress.
1. The standard tool does not export images and other media data. Instead, the importer reads the image URL in the XML and attempts to re-download the file from a remote location. However, this requires that the import source site is public and the media file URL is accessible. If the site is local or private, the media download will fail and only an empty attachment post will be created.
This plugin extracts images and other media data and WordPress database information and exports them together in a single ZIP file, eliminating such problems.
2. The standard tool does not export revisions, but this plugin provides export and import functions.
3. The standard tool allows you to select what to export for each post type, but not for each individual post.
This plugin allows you to select what to export for each individual post.

== Related Links ==

* [POST MIGRATION:Github](https://github.com/itmaroon/post-migration)
* [block-class-package:GitHub](https://github.com/itmaroon/block-class-package)  
* [block-class-package:Packagist](https://packagist.org/packages/itmar/block-class-package) 

== Installation ==

1. From the WP admin panel, click “Plugins” -> “Add new”.
2. In the browser input box, type “POST MIGRATION”.
3. Select the “POST MIGRATION” plugin and click “Install”.
4. Activate the plugin.

OR…

1. Download the plugin from this page.
2. Save the .zip file to a location on your computer.
3. Open the WP admin panel, and click “Plugins” -> “Add new”.
4. Click “upload”.. then browse to the .zip file downloaded from this page.
5. Click “Install”.. and then “Activate plugin”.


== Frequently asked questions ==


== Screenshots ==
1. Post data export settings and execution screen
2. Post data import settings and execution screen
3. Post data import results display screen

== Changelog ==

= 0.1.0 =
* Release

== Arbitrary section ==
PHP class management is now done using Composer.  
[GitHub](https://github.com/itmaroon/block-class-package)  
[Packagist](https://packagist.org/packages/itmar/block-class-package)
 

== Third Party Libraries ==

This plugin uses the following third-party libraries:

=== FileSaver.js ===
- Purpose: Used to enable client-side file saving functionality (e.g., downloading generated ZIP files in the browser).
- Library URL: https://github.com/eligrey/FileSaver.js/
- License: MIT License
- Resources : https://github.com/eligrey/FileSaver.js

Copyright (c) 2016 Eli Grey.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

=== JSZip ===
- Purpose: Used to create ZIP files directly on the client side in the browser.
- Library URL: https://github.com/Stuk/jszip
- License: MIT License

Copyright (c) 2009-2022 Stuart Knightley, David Duponchel,
          Franz Buchinger, António Afonso

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.



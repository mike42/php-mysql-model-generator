PHP-MySQL Model Generator
=========================

This script is designed to fast-track the development of small, well-defined web applications. An application name and MySQL database schema are the only inputs:

        ./generate.php foobar < foobar-schema.sql

An SQL parser will interpret the schema, and generate a series of PHP models and controllers. These files are all generated with [Doxygen](http://www.stack.nl/~dimitri/doxygen/)-ready comments. The resulting object-oriented code includes data type awareness and referential integrity checking. It uses smart greedy fetching to retrieve parent rows, and generates models which can be filtered to remove sensitive fields for output. This fetching is diagrammed with [Graphviz](http://www.graphviz.org/), just run "make" in the doc/ folder of your generated project.

You can this project to generate most of the server-side code. You will still need to write your own user-authentication (see lib/Util/Session.php) and permissions (see site/permissions.php) as needed. The client side of the app should simply be a HTML file, which can be set up to retrieve data from api.php, using the Backbone.js framework.

External Libraries
------------------
The client-side code uses the following libraries:
- [jQuery](jquery.com)
- [Bootsrap](http://getbootstrap.com/)
- [Backbone.js](http://backbonejs.org)
- [Underscore.js](http://underscorejs.org/)


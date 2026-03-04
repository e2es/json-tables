    === JSON Tables ===
    Contributors:
    Donate link:
    Tags: plugins, updates
    Requires at least: 5.0
    Tested up to: 6.5.2
    Stable tag: 4.3
    Requires PHP: 8.0
    License: GPLv2 or later
    License URI: https://www.gnu.org/licenses/gpl-2.0.html

    This plugin allows a scheduled cron job to download a JSON from a directory and update the database with the new data. Then allowing a shortcode to embed the table.

    == Description ==

    This plugin allows a scheduled cron job to download a JSON from a directory and update the database with the new data. Then allowing a shortcode to embed the table.

    == Changelog ==
    
    = 1.0.1 = 
    * Base creation of plugin

    = 1.0.2 =
    * Updated github directory and tested versions.

    = 1.0.3 =
    * Added new functionality for AWS S3 Credentials

    = 1.0.4 =
    * Fixed a bug where it was trying to send AWS security credentials when it was not needed.

    = 1.0.5 =
    * Added optional table title class to JSON

    = 1.0.6 =
    * Fixed a bug where the cron jobs were not working.

    = 1.0.7 =
    * Added settings page tab navigation (Settings / Request Log).
    * Added request logging to track every sync attempt with status and error details.
    * Added "Clear Log" button to purge request log entries.

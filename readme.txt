=== Term Steward ===
Contributors: klogic563
Tags: categories, tags, taxonomy, administration, cleanup
Requires at least: 6.6
Tested up to: 7.1
Stable tag: 0.1.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely rename, merge, delete, preview, and undo changes to standard WordPress categories and tags.

== Description ==

Term Steward helps administrators organize the standard WordPress categories and tags from the dashboard.

Version 0.1.1 supports:

* Renaming terms and changing a slug only when a new slug is explicitly entered.
* Merging one or more terms into an existing term in the same taxonomy.
* Deleting terms that have no relationships anywhere in WordPress.
* Previewing every operation before execution.
* Bounded batch execution and resuming an interrupted operation.
* Operation history.
* Safely undoing supported changes.

Relationship changes apply only to published posts of the standard `post` post type. Term Steward does not change relationships on pages, custom post types, drafts, private posts, scheduled posts, pending posts, trashed posts, or auto-drafts. Deletion safety checks include relationships across all WordPress objects.

Custom post types and custom taxonomies are outside the scope of version 0.1.1. AI classification, CSV import and export, scheduled execution, and redo are not supported.

Back up your database and uploads before using Term Steward on a production site. Test the operation in a staging environment first.

Get support and report issues through [GitHub Issues](https://github.com/k-logic563/term-steward/issues). The source code is available in the [GitHub repository](https://github.com/k-logic563/term-steward).

== Installation ==

1. In the WordPress dashboard, go to Plugins > Add New Plugin > Upload Plugin and select the distribution ZIP.
2. Install and activate Term Steward.
3. Go to Tools > Term Steward.
4. Open the Categories or Tags tab and select the terms to process.
5. Configure Rename, Merge, or Delete in the action panel, then choose Add to plan.
6. Open Operation plan and choose Review changes.
7. Review the targets, resulting values, affected post count, retention or deletion status, and warnings.
8. Choose Cancel to close the preview without changes, or Execute to start processing.
9. When needed, open Operation history, preview the undo operation, and choose Undo.

Access requires permission to manage categories and edit other users' published posts.

== Frequently Asked Questions ==

= Which posts and taxonomies are supported? =

Version 0.1.1 supports only the standard `category` and `post_tag` taxonomies. Relationship changes apply only to published posts of the standard `post` post type. Custom post types and custom taxonomies are not supported.

= What does "globally unused" mean? =

It means that a term has no relationships anywhere in WordPress, including drafts, pages, and custom post types. A term used by an excluded object is not deleted.

= Can Undo always restore every change? =

No. Undo restores only changes actually made by the original operation and only after safety checks pass. It does not overwrite later administrator changes. Conflicts can make an operation unavailable for undo or cause a partial failure.

= Is history data removed when I delete the plugin? =

No. Deleting Term Steward from the WordPress dashboard leaves the operation history, operation items, change journal tables, and database schema version option in the database. This preserves audit and recovery data, interrupted-operation state, and history for a later reinstall. Version 0.1.1 does not provide a complete data-removal setting.

= Is data from the former development name migrated automatically? =

No. Term Steward uses its own `term_steward_*` tables and option. It does not read, modify, migrate, or delete data that uses identifiers from the former development name because that data may belong to another plugin.

= Should I create a backup before running an operation? =

Yes. On a production site, back up the database and uploads and verify the restore procedure before running an operation.

= Where can I report a problem? =

Report it through [GitHub Issues](https://github.com/k-logic563/term-steward/issues) with reproduction steps and environment details. Do not include passwords, cookies, nonces, API keys, personal data, or a complete database dump.

== Screenshots ==

1. Search panel, action panel, and term list on the Categories screen.
2. Operation plan combining category and tag actions for review.
3. Preview modal showing the planned changes and affected post counts.
4. Operation history showing results and available undo actions.

== Changelog ==

= 0.1.1 =

* Changed public plugin metadata and the WordPress.org readme to English.
* Changed gettext source strings to English for WordPress.org language packs.
* Removed bundled translation loading and excluded local translation artifacts from the distribution.
* Added `composer.json` to the distribution package.

= 0.1.0 =

* Added search, filtering, sorting, and pagination for standard categories and tags.
* Added renaming, explicit slug changes, same-taxonomy merges, and deletion of globally unused terms.
* Added previews, bounded batches, resume support, operation history, and safety-checked undo.

== Upgrade Notice ==

= 0.1.1 =

This review-fix release uses WordPress.org language packs and includes Composer metadata in the distribution. Back up the database before upgrading.

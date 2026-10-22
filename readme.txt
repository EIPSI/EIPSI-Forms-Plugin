=== EIPSI Forms ===
Tags: research, forms, longitudinal, assessments, surveys
Requires at least: 7.1
Tested up to: 7.1.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create assessments and manage cross-sectional and longitudinal research studies in WordPress.

== Description ==
EIPSI Forms combines a visual Assessment Editor, Study Engine, participant runtime, email templates, study management, datasets and an external API.

The administration interface and initial default texts support English and Spanish. Researchers can edit participant-facing content in any language.

== Installation ==
1. Obtain a built installation package. GitHub's source ZIP excludes required build artifacts.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin, install and activate.
3. Open EIPSI Forms > Configuration. Review storage, SMTP, privacy and the scheduler before collecting data.
4. Create and publish an assessment, configure a study, test its public experience and activate it.

For source builds and packaging, see docs/installation.md. Compatibility evidence and limits are in docs/compatibility.md.

== Requirements ==
WordPress 7.1 or later. PHP 7.4 is the declared language floor; PHP 8.3 is the reference integration environment. The reference database is MariaDB 11.8.9 with InnoDB/JSON support. Other database versions and MySQL have not been certified by the current CI matrix.

PHP extensions used include mysqli, OpenSSL, mbstring and ZipArchive (XLSX). Use HTTPS, restricted administrative access and dedicated backups for research data.

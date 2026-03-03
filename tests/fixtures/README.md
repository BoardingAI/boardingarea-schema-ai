# Fixture Posts

This folder contains a WordPress WXR import file with sample posts that map to each supported schema type. Use these to validate single-post generation and the server-side validator.

## Import Steps

1. In WordPress, go to Tools > Import.
2. Install the "WordPress" importer if prompted.
3. Import `schema-fixtures.xml`.
4. After import, open any fixture post and use the SchemaMind AI meta box.

## Notes

- Each fixture post sets `_basai_schema_template_id` to the intended schema type.
- The Review fixture also sets `_basai_schema_reviewed_type` to `LocalBusiness`.

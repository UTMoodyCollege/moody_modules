# Moody Modules

## Setup on new site
- Go into web/modules/custom/moody_modules
- Run `for d in moody_custom_fields/*/ ; do fin drush en "$(basename "${d%/}")" -y; done`

## Feature Pages – Editors Picks

The block’s Columns setting supports one to four columns. Existing and new
blocks default to two (`col-12 col-sm-6`), with one column on small screens.
The setting applies to both the legacy View and ordered/custom item grids.

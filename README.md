# Moody Modules

## Setup on new site
- Go into web/modules/custom/moody_modules
- Run `for d in moody_custom_fields/*/ ; do fin drush en "$(basename "${d%/}")" -y; done`
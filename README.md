# Työllisyyspalvelut Helsinki Drupal 9 site

Drupal 9 website for the Työllisyyspalvelut Helsinki project.

## Related repositories
- [Employment services content sync](https://github.com/City-of-Helsinki/employment-services-content-sync)
- [React UI](https://github.com/City-of-Helsinki/employment-services-ui)

## Environments

Env | Branch | Drush alias | URL | Notes
--- | ------ | ----------- | --- | -----
development | develop | - | https://drupal-tyollisyyspalvelut-helfi.docker.so/ | Local development environment
production | main | @main | https://edit.tyollisyyspalvelut.hel.fi/ | Production site

## Requirements

You need to have these applications installed to operate on all environments:

- [Docker](https://github.com/druidfi/guidelines/blob/master/docs/docker.md)
- [Stonehenge](https://github.com/druidfi/stonehenge)
- For the new person: Your SSH public key needs to be added to servers

## Create and start the environment

For the first time (new project):

``
$ make new
``

Stop project:

``
$ make stop
``

Stop project and remove app container:

``
$ make down
``

Start project, rebuild and update configuration:

``
$ make up; make build; make post-install
``

Install fresh Drupal site from existing configuration:

``
$ make build; make drush-si; make post-install
``

Start project, update all packages and sync db from production:

``
$ make fresh
``

**Note:** Will not work at this point, since the production environment has not been set up.
## Update Drupal and composer modules

Update all modules and composer packages:

``
$ make composer-update
``

Update only Drupal core:

``
$ make drupal-update
``

**Note:** After updates, clear caches, run database updates and export possibly changed configuration:

``
$ make drush-cr; make drush-updb; make drush-cex
``

Update Composer.lock if outdated (after merges, etc):

```
# Login into app container first:
$ make shell

# Update lock file:
$ composer update --lock
```

## Configuration management

Export settings:

``
$ make drush-cex
``

Import settings:

``
$ make drush-cim
``

## Other useful commands
```
# Login to app container:
$ make shell

# Login with Drush
$ make drush-uli

# Check Drupal coding style
$ make lint-drupal

# Automatically fix Drupal coding style errors
$ make fix-drupal
```

### Coding standards
Follow Drupal's coding standards: https://www.drupal.org/docs/develop/standards

City of Helsinki's coding standars and best practices: https://dev.hel.fi/

Check for coding style violantions by running `$ make lint-drupal`

### Gitflow workflow
The Gitflow workflow is followed, with the following conventions:

**Main branch**: `develop`. All feature branches are created from `develop` and merged back with pull requests. All new code must be added with pull requests, not committed directly.

**Production branch:** `main`. Code running in production. Code is merged to `main` with release and hotfix branches.

**Feature branches**: For example, `TH-add-content-type`, Always created from and merged back to `develop` with pull requests after code review and testing.

**Release branches**: Code for future and currently developed releases. Should include the version number, for example: `1.1.0`

**Hotfix branches**: Branches for small fixes to production code. Should include the word hotfix, for example: `TH-hotfix-drupal-updates`. Remember to also merge these back to `develop`.

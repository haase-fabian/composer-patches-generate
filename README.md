# Composer Patches Generate Command

Generate .patch files with a single command.

## Install
Make sure `git` and `find` are installed.
```shell
git version           
-> git version 1.51.0

find --version
-> find (GNU findutils) 4.10.0-dirty
```

```shell
composer require --dev fhaase/composer-patches-command

# Recommended to apply patches on install
composer require cweagans/composer-patches
```

## Usage

### Generate or update patch files

1. Create a copy of a file with `*.old` suffix inside one of the composer packages
2. Modify the original file \
   There should now be files like this:
   ```terminaloutput
   UserFactory.php
   UserFactory.php.old
   ```
3. Run the command with the package name \
   `vendor/bin/composer-patches-generate vendor/example`
4. When prompted, you can give a short description to generate configuration for `cweagans/composer-patches` \ 
   ```json
   {
      "extra": {
          "patches": {
              "vendor/example": {
                  "description": "patches/vendor_example/UserFactory.patch"
              }
          }
      }
   }
   ```

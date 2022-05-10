# GravityExport API

## Installation Guide

1. Deploy the contents of this repo to a server (dealers choice)
2. copy the `.env` file to `.env.local` and fill out the proper values
   1. A Symfony key (if none is present) can be [generated here](https://coderstoolbox.online/toolbox/generate-symfony-secret)
   2. Values are directly placed after the `=`, without a space, eg. `APP_SECRET=TheSecretKey`
3. On the server preform a `composer install -o`. This will install the dependencies and optimize the autoloader

**Note:** On production make sure the `APP_ENV` is set to `prod` to avoid unnecessary information leaks.

## Dropbox App Configuration

The [Dropbox App](https://www.dropbox.com/developers/apps) needs to have a **Redirect URL**.
This should be: `https://full-url-of-the-app.com/dropbox/endpoint`.

# `migrations` plugin for zicht/z

Add migrations jobs to run only once on an remote envirement. 

This plugin will scan the migrations path for files and merge the defined migrations files with the local z file so it can be run when the migration hasn't been run on the the remote environment. This is all done in-memory so no files are changed.

The default search pattern is: `./z_migrations/*.yml` and can be overwritten with the `migrations.path` property.

An migrations reference file is stored on the remote server and to make sure this plugin keeps working you should add the following to the `rsync.exclude` file:

```
.z.migrations
z_migrations
```

This will exclude the local migration files and makes sure the migrations files on the server won`t be removed.

To check which migrations are executed you could do and `z deploy production --explain` or use the `z env:migrations:list` to check the state of the migrations files. 

## Example:

```yml
# z_migrations/180828_clear_image_cache.yml

deploy:
    post: ssh $(envs[target_env].ssh) "cd $(envs[target_env].root) && php app/console --env=$(target_env) liip:imagine:cache:remove"

```


```yml
# z2.yml

plugins: ['migrations' ....

```

After an deploy on staging you should get the following with:

```
z envmigrations:list staging
```

```
+------------------------------+------------------------------------------+----------+---------------------------+
| file                         | ref                                      | executed | date                      |
+------------------------------+------------------------------------------+----------+---------------------------+
| 180828_clear_image_cache.yml | 0964385a220b943dda86dec9b92f347bd56301b1 | ✔        | 2018-08-28T14:34:16+02:00 |
+------------------------------+------------------------------------------+----------+---------------------------+

```


# Maintainer(s)
* Philip bergman <philip@zicht.nl>

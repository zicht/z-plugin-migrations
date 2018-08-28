# `migrations` plugin for zicht/z

Define migrations jobs to run only once on for example `post deploy`. 

This plugin will scan the migrations path for files (which default is `./z_migrations/*.yml` but can be overwritten with the `migrations.path` property) and merge the config with the local z file so can be run when the migration is not run on the the given environment.

All migrations are stored on the remote server and to make sure this plugin keeps working you should add the following to the `rsync.exclude` file:   

```
.z.migrations
z_migrations
```

This will exclude the local migration files and makes sure the migrations files on the server won`t be removed.

To check which migrations are executed you could do and `z deploy production --explain` or use the `z migrations:list` to check the state of the migrations files. 

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
z migrations:list staging
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

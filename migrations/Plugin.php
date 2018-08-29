<?php
/**
 * @author    Philip Bergman <philip@zicht.nl>
 * @copyright Zicht Online <http://www.zicht.nl>
 */

namespace Zicht\Tool\Plugin\Migrations;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Yaml\Yaml;
use Zicht\Tool\Container\Container;
use Zicht\Tool\Container\ContainerBuilder;
use Zicht\Tool\Plugin as BasePlugin;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Class Plugin
 *
 * @package Zicht\Tool\Plugin\Migrations
 */
class Plugin extends BasePlugin
{

    private $ctx = [];

    /**
     * Appends the refs configuration
     *
     * @param \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $rootNode
     * @return void
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('migrations')
                    ->children()
                        ->scalarNode('path')->end()
                    ->end()
                ->end()
            ->end();
    }


    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        $container->decl(
            ['migrations', 'update'],
            function(Container $c) {
                if (null === $env = $c->resolve('target_env')) {
                    return;
                }
                $this->setMigrations($c, $env, array_merge($this->getMigrations($c, $env), $this->ctx));
            }
        );

        $container->method(
            ['migrations', 'print_list'],
            function(Container $c, $env) {
                $migrations = $this->getMigrations($c, $env);
                $files = glob($c->resolve(['migrations', 'path']));
                $table = new Table($c->output);
                $table->setHeaders(['file', 'ref', 'executed', 'date', 'deploy commit', 'comment']);
                foreach ($files as $file) {
                    if (false !== $index = array_search((basename($file)), array_column($migrations, 0))) {
                        $row = [
                            basename($file),
                            $migrations[$index][1],
                            "<fg=green;options=bold>✔</>",
                            $migrations[$index][2],
                            $migrations[$index][3],
                            null,
                        ];
                        if ($migrations[$index][1] !== sha1_file(realpath($file))) {
                            $row[5] = "<comment>Migrations was executed but looks like file has been changed.</comment>";
                        }

                        $table->addRow($row);
                    } else {
                        $table->addRow([$file, null, "<fg=yellow;options=bold>✘</>", null, null, null]);
                    }
                }
                $table->render();
            }
        );

        $container->method(
            ['migrations', 'is_valid'],
            function(Container $c, $file) {
                if (null === $env = $c->resolve('target_env')) {
                    return false;
                }
                $migrations = $this->getMigrations($c, $env);
                if (false !== $index = array_search(basename($file), array_column($migrations, 0))) {
                    list(,$content,$date,$commit) = $migrations[$index];
                    if (sha1_file(realpath($file)) !== $content) {
                        $c->output->writeln(sprintf('# <comment>File "%s" was run on "%s" while deploying "%s", but looks like file has been changed.</comment>', basename($file), $date, $commit));
                    }
                    return false;
                } else {
                    $this->ctx[] = [basename($file), sha1_file(realpath($file)), (new \DateTime)->format(\DateTime::RFC3339), $c->resolve(['build', 'version'])];
                    return true;
                }
            }
        );
    }


    /**
     * @param Container $c
     */
    private function setMigrations(Container $c, $env, array $migrations = [])
    {
        $content = '';
        foreach ($migrations as $data) {
            $content .= implode(" ", $data) . '\n';
        }
        $c->exec(
            sprintf(
                'ssh %s "cd %s && echo -en \'%s\' | column -t > .z.migrations"',
                $c->resolve(['envs', $env, 'ssh']),
                $c->resolve(['envs', $env, 'root']),
                $content
            )
        );
    }


    /**
     * @param Container $c
     * @return array
     */
    private function getMigrations(Container $c, $env)
    {
        static $migrations;
        if (!$migrations) {
            $migrations = [];
            $list = $c->helperExec(
                sprintf(
                    "ssh %s \"cd %s && [ -f .z.migrations ] && cat .z.migrations || echo ''\"",
                    $c->resolve(['envs', $env, 'ssh']),
                    $c->resolve(['envs', $env, 'root'])
                )
            );
            foreach (explode("\n", $list) as $line) {
                if (empty($line)) {
                    continue;
                }
                // should be an line with following pattern:
                // FILE_NAME FILE_CONTENT_HASH MIGRATION_DATE COMMIT_HASH
                $migrations[] = preg_split('/\s+/', $line, 4);
            }
            $migrations = array_filter($migrations);
        }

        return $migrations;
    }


    /**
     * @param ContainerBuilder $container
     */
    public function setContainerBuilder(ContainerBuilder $c)
    {
        foreach (glob($c->config['migrations']['path']) as $file) {
            $data = Yaml::parse(file_get_contents($file));
            foreach ($data as $task => $name) {
                foreach ($name as $step => $jobs) {
                    foreach ((array)$jobs as $job) {
                        $c->config['tasks'][$task][$step][] = sprintf('@(if migrations.is_valid("%s")) %s', realpath($file), $job);
                    }
                }
            }

        }
        $c->config['tasks']['deploy']['post'][] = "$(migrations.update)";
    }
}

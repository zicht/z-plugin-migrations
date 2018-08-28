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

    private $ctx;

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
                $migrations = [];
                foreach ($this->getMigrations($c, $env) as list($hash, $date)) {
                    $migrations[$hash] = $date;
                }
                foreach ($this->ctx as $hash => $date) {
                    $migrations[$hash] = $date;
                }
                $this->setMigrations($c, $env, $migrations);
            }
        );

        $container->method(
            ['migrations', 'print_list'],
            function(Container $c, $env) {
                $migrations = $this->getMigrations($c, $env);
                $files = array_map('basename', glob($c->resolve(['migrations', 'path'])));
                $table = new Table(new StreamOutput(STDOUT));
                $table->setHeaders(['file', 'ref', 'executed', 'date']);
                foreach ($files as $file) {
                    if (false !== $index = array_search(sha1($file), array_column($migrations, 0))) {
                        $table->addRow([$file, $migrations[$index][0], "<fg=green;options=bold>✔</>", $migrations[$index][1]]);
                    } else {
                        $table->addRow([$file, $migrations[$index][0], "<fg=yellow;options=bold>✘</>", $migrations[$index][1]]);
                    }
                }
                $table->render();
            }
        );

        $container->method(
            ['migrations', 'is_valid'],
            function(Container $c, $file) {
                $hash = sha1(basename($file));
                if (null === $env = $c->resolve('target_env')) {
                    return;
                }
                if (!in_array($hash, array_column($this->getMigrations($c, $env), 0))) {
                    if (!isset($this->ctx[$hash])) {
                        $this->ctx[$hash] = new \DateTimeImmutable();
                    }
                    return true;
                }
                return false;
            }
        );

    }


    /**
     * @param Container $c
     */
    private function setMigrations(Container $c, $env, array $migrations = [])
    {

        $content = '';

        foreach ($migrations as $hash => $time) {
            $content .= sprintf('%s %s\n', $hash, $time->format(\DateTime::RFC3339));
        }

        $c->exec(
            sprintf(
                'ssh %s "cd %s && echo -en \'%s\' > .z.migrations"',
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
                $migrations[] = explode(" ", $line, 2);
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

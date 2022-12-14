<?php

namespace Survos\WikiBundle;

use Survos\WikiBundle\Service\WikiService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Wikidata\Wikidata;


class SurvosWikiBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $serviceId = 'survos_wiki.wiki_service';
        $container->services()->alias(WikiService::class, $serviceId);
        $definition = $builder->autowire($serviceId, WikiService::class)
            ->setPublic(true);

        $definition->setArgument('$cache', new Reference('cache.app'));
        $definition->setArgument('$client', new Reference('http_client'));
        $definition->setArgument('$logger', new Reference('logger'));
//        $definition->setArgument('$wikidata', new Reference(Wikidata::class));

        $definition->setArgument('$searchLimit', $config['search_limit']);
        $definition->setArgument('$cacheTimeout', $config['cache_timeout']);


        // $builder->setParameter('survos_workflow.direction', $config['direction']);

        // twig classes

/*
$definition = $builder
->autowire('survos.barcode_twig', BarcodeTwigExtension::class)
->addTag('twig.extension');

$definition->setArgument('$widthFactor', $config['widthFactor']);
$definition->setArgument('$height', $config['height']);
$definition->setArgument('$foregroundColor', $config['foregroundColor']);
*/

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('search_limit')->defaultValue(20)->end()
                ->integerNode('cache_timeout')->defaultValue(0)->end()
                ->booleanNode('enabled')->defaultTrue()->end()
            ->end();
    }

}

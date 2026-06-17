<?php

declare(strict_types=1);

namespace Survos\WikiBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;
use Survos\WikiBundle\Service\WikidataService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Wikidata/Wikipedia lookup service with a cache, an admin menu, and a UI.
 *
 * Commands (src/Command) and controllers (src/Controller) are auto-registered by
 * AbstractSurvosBundle; entities (src/Entity) by HasDoctrineEntities; the UI's
 * routes by HasConfigurableRoutes. The lookup service is registered in
 * config/services.php.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosWikiBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;
    use HasConfigurableRoutes;

    protected function doctrineAlias(): string
    {
        return 'Wiki';
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/wiki');

        $children
            ->integerNode('search_limit')->defaultValue(20)->end()
            ->integerNode('cache_timeout')->defaultValue(86400)->end()
            ->booleanNode('enabled')->defaultTrue()->end()
            // Properties the app cares about, alias => P-code. get() fetches these by
            // default (no per-call props needed); they seed the wiki_property cache.
            // Apps override the whole map in their own survos_wiki.yaml.
            ->arrayNode('properties')
                ->useAttributeAsKey('alias')
                ->scalarPrototype()->end()
                ->defaultValue([
                    'image'             => 'P18',
                    'instance_of'       => 'P31',
                    'subclass_of'       => 'P279',
                    'country'           => 'P17',
                    'official_language' => 'P37',
                    'location'          => 'P131',
                    'date_of_birth'     => 'P569',
                    'date_of_death'     => 'P570',
                    'place_of_birth'    => 'P19',
                    'place_of_death'    => 'P20',
                    'sex_or_gender'     => 'P21',
                    'citizenship'       => 'P27',
                    'occupation'        => 'P106',
                    'description'       => 'P2094',
                    'official_name'     => 'P1448',
                    'website'           => 'P856',
                    'inception'         => 'P571',
                    'dissolved'         => 'P576',
                    'headquarters'      => 'P159',
                    'founded_by'        => 'P740',
                    'award_received'    => 'P166',
                ])
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $this->captureRouteConfig($config);
        $this->registerRouteLoader($builder);

        // Commands + controllers are auto-scanned by the parent; the lookup service
        // is registered here with its config values pushed in directly.
        $container->services()
            ->set(WikidataService::class)
            ->arg('$searchLimit', $config['search_limit'])
            ->arg('$cacheTtl', $config['cache_timeout'])
            ->public()
            ->autowire()
            ->autoconfigure();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }
}

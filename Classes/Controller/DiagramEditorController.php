<?php

declare(strict_types=1);

namespace Sandstorm\MxGraph\Controller;

use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Sandstorm\MxGraph\DiagramIdentifierSearchService;

class DiagramEditorController extends ActionController
{
    private const LOCAL_DRAWIO_EMBED_URL = 'LOCAL';

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject]
    protected DiagramIdentifierSearchService $diagramIdentifierSearchService;

    #[Flow\InjectConfiguration(path: 'drawioEmbedUrl')]
    protected string $drawioEmbedUrl;

    #[Flow\InjectConfiguration(path: 'drawioEmbedParameters')]
    protected array $drawioEmbedParameters;

    #[Flow\InjectConfiguration(path: 'drawioConfiguration')]
    protected array $drawioConfiguration = [];


    public function indexAction(Node $diagramNode): void
    {
        $drawioEmbedUrlWithParameters = $this->drawioEmbedUrl;
        if ($drawioEmbedUrlWithParameters === self::LOCAL_DRAWIO_EMBED_URL) {
            $drawioEmbedUrlWithParameters = $this->uriBuilder->uriFor('offlineLocalDiagramsNet');
        }
        $drawioEmbedParameters = $this->drawioEmbedParameters;
        // these parameters must be hard-coded; otherwise our application won't work
        $drawioEmbedParameters['embed'] = '1';
        $drawioEmbedParameters['configure'] = '1';
        $drawioEmbedParameters['proto'] = 'json';

        $drawioEmbedUrlWithParameters .= '?' .  http_build_query($drawioEmbedParameters);

        $nodeAddressFactory = NodeAddressFactory::create(
            $this->contentRepositoryRegistry->get($diagramNode->subgraphIdentity->contentRepositoryId)
        );

        $this->view->assign('diagram', $diagramNode->getProperty('diagramSource'));
        $this->view->assign('diagramNode', $nodeAddressFactory->createFromNode($diagramNode));
        $this->view->assign('drawioEmbedUrlWithParameters', $drawioEmbedUrlWithParameters);
        $this->view->assign('drawioConfiguration', is_array($this->drawioConfiguration) ? $this->drawioConfiguration : []);

    }

    /**
     */
    public function offlineLocalDiagramsNetAction()
    {
    }

    #[Flow\SkipCsrfProtection]
    public function saveAction(Node $node, string $xml, string $svg): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get($node->subgraphIdentity->contentRepositoryId);
        $subgraph = $contentRepository->getContentGraph()->getSubgraph(
            $node->subgraphIdentity->contentStreamId,
            $node->subgraphIdentity->dimensionSpacePoint,
            $node->subgraphIdentity->visibilityConstraints
        );
        $propertyValuesToWrite = [];
        if (empty($svg)) {
            // XML without SVG -> autosaved - not supported right now.
            $propertyValuesToWrite['diagramSourceAutosaved'] = $xml;
            throw new \RuntimeException("TODO - autosave not supported right now.");
        }

        $propertyValuesToWrite['diagramSource'] = $xml;
        // NEW since version 3.0.0
        $propertyValuesToWrite['diagramSvgText'] = $svg;

        $diagramIdentifier = $node->getProperty('diagramIdentifier');
        if (!empty($diagramIdentifier)) {
            // also update related diagrams
            foreach ($this->diagramIdentifierSearchService->findRelatedDiagramsWithIdentifierExcludingOwn($diagramIdentifier, $node) as $relatedDiagramNode) {
                $relatedDiagramNode->setProperty('diagramSource', $xml);
                $relatedDiagramNode->setProperty('diagramSvgText', $svg);
            }
        }

        // BEGIN DEPRECATION since version 3.0.0
        $persistentResource = $this->resourceManager->importResourceFromContent($svg, 'diagram.svg');

        $image = $node->getProperty('image');
        if ($image instanceof Asset) {
            // BUG: this also changes the live workspace - nasty. But if we remove it, we get 1000s of assets
            // cluttering the Media UI.
            $image->setResource($persistentResource);
        } else {
            $image = new Image($persistentResource);
            $propertyValuesToWrite['image'] = $image;
        }
        // END DEPRECATION since version 3.0.0

        $this->contentRepositoryRegistry->get($node->subgraphIdentity->contentRepositoryId)->handle(
            SetNodeProperties::create(
                $node->subgraphIdentity->contentStreamId,
                $node->nodeAggregateId,
                $node->originDimensionSpacePoint,
                PropertyValuesToWrite::fromArray($propertyValuesToWrite)
            )
        )->block();

        return 'OK';
    }
}

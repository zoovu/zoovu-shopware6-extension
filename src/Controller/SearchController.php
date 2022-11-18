<?php
declare(strict_types=1);
namespace semknox\search\Controller;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\SearchController as ShopwareSearchController;
use Shopware\Storefront\Framework\Cache\Annotation\HttpCache;
use Shopware\Storefront\Page\Search\SearchPage;
use Shopware\Storefront\Page\Search\SearchPageLoader;
use Shopware\Storefront\Page\Suggest\SuggestPageLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use semknox\search\Request\Handler\FilterHandler;
use Shopware\Core\Content\Product\ProductDefinition;
use semknox\search\Framework\SemknoxsearchHelper;
use function GuzzleHttp\Psr7\parse_query;
class SearchController extends ShopwareSearchController
{
    /**
     * @var SearchPageLoader
     */
    private $searchPageLoader;
    /**
     * @var SuggestPageLoader
     */
    private $suggestPageLoader;
    private $productDefinition;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    /**
     * 
     * @var SearchController
     */
    private $decorated;
    private $preferences = [];
    public function __construct(
        ShopwareSearchController $deco,
        SearchPageLoader $searchPageLoader,
        SuggestPageLoader $suggestPageLoader,
        AbstractProductSearchRoute $abstractProductSearchRoute,
        SemknoxsearchHelper $helper        
    ) {
        parent::__construct($searchPageLoader, $suggestPageLoader, $abstractProductSearchRoute);
        $this->decorated = $deco;
        $this->searchPageLoader = $searchPageLoader;
        $this->suggestPageLoader = $suggestPageLoader;
        $this->semknoxSearchHelper = $helper;
        $this->preferences = $this->semknoxSearchHelper->getPreferences();
    }
    /**
     * @HttpCache()
     * @RouteScope(scopes={"storefront"})
     * @Route("/search", name="frontend.search.page", methods={"GET"})
     */
    public function search(SalesChannelContext $context, Request $request): Response
    {
        $origContext = clone $context; $origRequest = clone $request;
        if ($this->semknoxSearchHelper->useSiteSearch($context, $request) === false) {
            return $this->decorated->search($context, $request);
        }
        if ($response = $this->handleSiteSearchParams($request)) {
            return $response;
        }
        try {            
            $page = $this->searchPageLoader->load($request, $context);
        } catch (MissingRequestParameterException $missingRequestParameterException) {
            return $this->forwardToRoute('frontend.home.page');
        }        
        $redir = $this->getRedirect($page);
        if ($redir!='') {
            return $this->redirect($redir);
        }
       if ( ($this->preferences['semknoxRedirectOn1']) && ($page->getListing()->getTotal() == 1) ) {
           $ent = $page->getListing()->getEntities()->first();
           if ($ent) {
                return $this->redirectToRoute('frontend.detail.page', ['productId' => $ent->getId()]);
           }
        }
        $useinternal = $this->getUseShopwareSearch($page);
        if ($useinternal > 0) {
            $this->getCleanRequest($origContext, $origRequest);
            return $this->decorated->search($origContext, $origRequest);
        }
        return $this->renderStorefront('@Storefront/storefront/page/search/index.html.twig', ['page' => $page]);
    }
    private function getCleanRequest(SalesChannelContext &$context, Request &$request) {
        $query = $request->getQueryString();
        $qa = parse_query($query);
        $qa['useinternal']=525;
        $request = $request->duplicate($qa);
    }
    private function getRedirect(SearchPage $page) {
        $ret='';
        $ext=$page->getListing()->getExtension('semknoxResultData');
        if ($ext) {
            $semknoxData = $ext->getVars();
            if (isset($semknoxData['data']['redirect'])) {
                $ret = $semknoxData['data']['redirect'];
            }
        }
        return $ret;
    }
    private function getUseShopwareSearch($page) {
        $ret=0;
        $ext=$page->getListing()->getExtension('semknoxResultData');
        if ($ext) {
            $semknoxData = $ext->getVars();
            if (isset($semknoxData['data']['useInternalSearch'])) {
                $ret = $semknoxData['data']['useInternalSearch'];
            }
        }
        return $ret;
    }
    /**
     * @HttpCache()
     * @RouteScope(scopes={"storefront"})
     * @Route("/suggest", name="frontend.search.suggest", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function suggest(SalesChannelContext $context, Request $request): Response
    {
        if ($this->semknoxSearchHelper->useSiteSearch($context, $request) === false) {
            return $this->decorated->suggest($context, $request);
        }
        $page = $this->suggestPageLoader->load($request, $context);
        return $this->renderStorefront(
            '@Storefront/storefront/layout/header/search-suggest.html.twig',
            ['page' => $page]
        );
    }
    /**
     * @HttpCache()
     *
     * Route to load the listing filters
     *
     * @RouteScope(scopes={"storefront"})
     * @Route("/widgets/search/{search}", name="widgets.search.pagelet", methods={"GET", "POST"},
     *     defaults={"XmlHttpRequest"=true})
     *
     * @throws MissingRequestParameterException
     */
    public function pagelet(Request $request, SalesChannelContext $context): Response
    {
        if ($this->semknoxSearchHelper->useSiteSearch($context, $request) === false) {
            return $this->decorated->pagelet($context, $request);
        }        
        $request->request->set('no-aggregations', true);
        $page = $this->searchPageLoader->load($request, $context);
        return $this->renderStorefront(
            '@Storefront/storefront/page/search/search-pagelet.html.twig',
            ['page' => $page]
        );
    }
    /**
     * @HttpCache()
     *
     * Route to load the listing filters
     *
     * @RouteScope(scopes={"storefront"})
     * @Route(
     *      "/widgets/search",
     *      name="widgets.search.pagelet.v2",
     *      methods={"GET", "POST"},
     *      defaults={"XmlHttpRequest"=true}
     * )
     *
     * @throws MissingRequestParameterException
     */
    public function ajax(Request $request, SalesChannelContext $context): Response
    {
        if ($this->semknoxSearchHelper->useSiteSearch($context, $request) === false) {
            return $this->decorated->ajax($context, $request);
        }
        $request->request->set('no-aggregations', true);
        $page = $this->searchPageLoader->load($request, $context);
        return $this->renderStorefront(
            '@Storefront/storefront/page/search/search-pagelet.html.twig',
            ['page' => $page]
        );
    }
    private function handleSiteSearchParams(Request $request): ?Response
    {
        return null;
        if ($uri = $this->filterHandler->handleSiteSearchParams($request)) {
            return $this->redirect($uri);
        }
        return null;
    }
}

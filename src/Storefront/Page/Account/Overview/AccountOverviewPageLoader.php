<?php declare(strict_types=1);

namespace Shopware\Storefront\Page\Account\Overview;

use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractCustomerRoute;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponseStruct;
use Shopware\Core\Content\Category\Exception\CategoryNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Event\RouteRequest\OrderRouteRequestEvent;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class AccountOverviewPageLoader
{
    /**
     * @var GenericPageLoaderInterface
     */
    private $genericLoader;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var AbstractOrderRoute
     */
    private $orderRoute;

    /**
     * @var AbstractCustomerRoute
     */
    private $customerRoute;

    public function __construct(
        GenericPageLoaderInterface $genericLoader,
        EventDispatcherInterface $eventDispatcher,
        AbstractOrderRoute $orderRoute,
        AbstractCustomerRoute $customerRoute
    ) {
        $this->genericLoader = $genericLoader;
        $this->eventDispatcher = $eventDispatcher;
        $this->orderRoute = $orderRoute;
        $this->customerRoute = $customerRoute;
    }

    /**
     * @throws CategoryNotFoundException
     * @throws CustomerNotLoggedInException
     * @throws InconsistentCriteriaIdsException
     * @throws MissingRequestParameterException
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): AccountOverviewPage
    {
        if (!$salesChannelContext->getCustomer() instanceof CustomerEntity) {
            throw new CustomerNotLoggedInException();
        }

        $page = $this->genericLoader->load($request, $salesChannelContext);

        $page = AccountOverviewPage::createFrom($page);
        $page->setCustomer($this->loadCustomer($salesChannelContext));

        if ($page->getMetaInformation()) {
            $page->getMetaInformation()->setRobots('noindex,follow');
        }

        $order = $this->loadNewestOrder($salesChannelContext, $request);

        if ($order !== null) {
            $page->setNewestOrder($order);
        }

        $this->eventDispatcher->dispatch(
            new AccountOverviewPageLoadedEvent($page, $salesChannelContext, $request)
        );

        return $page;
    }

    private function loadNewestOrder(SalesChannelContext $context, Request $request): ?OrderEntity
    {
        $criteria = (new Criteria())
            ->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING))
            ->addAssociation('lineItems')
            ->addAssociation('lineItems.cover')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('addresses')
            ->addAssociation('currency')
            ->addAssociation('documents.documentType')
            ->setLimit(1)
            ->addAssociation('orderCustomer');

        $apiRequest = new Request();

        $event = new OrderRouteRequestEvent($request, $apiRequest, $context);
        $this->eventDispatcher->dispatch($event);

        /** @var OrderRouteResponseStruct $responseStruct */
        $responseStruct = $this->orderRoute
            ->load($event->getStoreApiRequest(), $context, $criteria)
            ->getObject();

        return $responseStruct->getOrders()->first();
    }

    private function loadCustomer(SalesChannelContext $context): CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('requestedGroup');

        return $this->customerRoute->load(new Request(), $context, $criteria)->getCustomer();
    }
}

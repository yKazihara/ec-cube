<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Controller\Admin;

use Carbon\Carbon;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\ResultSetMapping;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\CustomerStatus;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Master\ProductStatus;
use Eccube\Entity\ProductStock;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\Admin\ChangePasswordType;
use Eccube\Form\Type\Admin\LoginType;
use Eccube\Repository\CustomerRepository;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\MemberRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Repository\ProductRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AdminController extends AbstractController
{
    /**
     * @var AuthorizationCheckerInterface
     */
    protected $authorizationChecker;

    /**
     * @var AuthenticationUtils
     */
    protected $helper;

    /**
     * @var MemberRepository
     */
    protected $memberRepository;

    /**
     * @var EncoderFactoryInterface
     */
    protected $encoderFactory;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * @var CustomerRepository
     */
    protected $customerRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var array 売り上げ状況用受注状況
     */
    private $excludes = [OrderStatus::PROCESSING, OrderStatus::CANCEL, OrderStatus::PENDING];

    /**
     * AdminController constructor.
     *
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param AuthenticationUtils $helper
     * @param MemberRepository $memberRepository
     * @param EncoderFactoryInterface $encoderFactory
     * @param OrderRepository $orderRepository
     * @param OrderStatusRepository $orderStatusRepository
     * @param CustomerRepository $custmerRepository
     * @param ProductRepository $productRepository
     */
    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker,
        AuthenticationUtils $helper,
        MemberRepository $memberRepository,
        EncoderFactoryInterface $encoderFactory,
        OrderRepository $orderRepository,
        OrderStatusRepository $orderStatusRepository,
        CustomerRepository $custmerRepository,
        ProductRepository $productRepository
    ) {
        $this->authorizationChecker = $authorizationChecker;
        $this->helper = $helper;
        $this->memberRepository = $memberRepository;
        $this->encoderFactory = $encoderFactory;
        $this->orderRepository = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->customerRepository = $custmerRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/login", name="admin_login")
     * @Template("@admin/login.twig")
     */
    public function login(Request $request)
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_homepage');
        }

        /* @var $form \Symfony\Component\Form\FormInterface */
        $builder = $this->formFactory->createNamedBuilder('', LoginType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIM_LOGIN_INITIALIZE, $event);

        $form = $builder->getForm();

        return [
            'error' => $this->helper->getLastAuthenticationError(),
            'form' => $form->createView(),
        ];
    }

    /**
     * 管理画面ホーム
     *
     * @param Request $request
     *
     * @return array
     *
     * @throws NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     *
     * @Route("/%eccube_admin_route%/", name="admin_homepage")
     * @Template("@admin/index.twig")
     */
    public function index(Request $request)
    {
        /**
         * 受注状況.
         */
        $excludes = [];
        $excludes[] = OrderStatus::PENDING;
        $excludes[] = OrderStatus::PROCESSING;
        $excludes[] = OrderStatus::CANCEL;
        $excludes[] = OrderStatus::DELIVERED;

        $event = new EventArgs(
            [
                'excludes' => $excludes,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIM_INDEX_ORDER, $event);
        $excludes = $event->getArgument('excludes');

        // 受注ステータスごとの受注件数.
        $Orders = $this->getOrderEachStatus($excludes);

        // 受注ステータスの一覧.
        $Criteria = new Criteria();
        $Criteria
            ->where($Criteria::expr()->notIn('id', $excludes))
            ->orderBy(['sort_no' => 'ASC']);
        $OrderStatuses = $this->orderStatusRepository->matching($Criteria);

        /**
         * 売り上げ状況
         */
        $event = new EventArgs(
            [
                'excludes' => $this->excludes,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIM_INDEX_SALES, $event);
        $this->excludes = $event->getArgument('excludes');

        // 今日の売上/件数
        $salesToday = $this->getSalesByDay(new \DateTime());
        // 昨日の売上/件数
        $salesYesterday = $this->getSalesByDay(new \DateTime('-1 day'));
        // 今月の売上/件数
        $salesThisMonth = $this->getSalesByMonth(new \DateTime());

        /**
         * ショップ状況
         */
        // 在庫切れ商品数
        $countNonStockProducts = $this->countNonStockProducts();

        // 取り扱い商品数
        $countProducts = $this->countProducts();

        // 本会員数
        $countCustomers = $this->countCustomers();

        $event = new EventArgs(
            [
                'Orders' => $Orders,
                'OrderStatuses' => $OrderStatuses,
                'salesThisMonth' => $salesThisMonth,
                'salesToday' => $salesToday,
                'salesYesterday' => $salesYesterday,
                'countNonStockProducts' => $countNonStockProducts,
                'countProducts' => $countProducts,
                'countCustomers' => $countCustomers,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIM_INDEX_COMPLETE, $event);

        // 推奨プラグイン
        $url = $this->eccubeConfig['eccube_package_repo_url'].'/plugins/recommended';
        list($json, $info) = $this->getRequestApi($url);
        $recommendedPlugins = json_decode($json, true);

        return [
            'Orders' => $Orders,
            'OrderStatuses' => $OrderStatuses,
            'salesThisMonth' => $salesThisMonth,
            'salesToday' => $salesToday,
            'salesYesterday' => $salesYesterday,
            'countNonStockProducts' => $countNonStockProducts,
            'countProducts' => $countProducts,
            'countCustomers' => $countCustomers,
            'recommendedPlugins' => $recommendedPlugins,
        ];
    }

    /**
     * Need move to common code
     *
     * @param $url
     * @return array
     */
    private function getRequestApi($url)
    {
        $curl = curl_init($url);

        // Option array
        $options = [
            // HEADER
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CAINFO => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
        ];

        // Set option value
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $info = curl_getinfo($curl);
        $message = curl_error($curl);
        $info['message'] = $message;
        curl_close($curl);

        log_info('http get_info', $info);

        return [$result, $info];
    }

    /**
     * 売上状況の取得
     *
     * @param Request $request
     *
     * @Route("/%eccube_admin_route%/sale_chart", name="admin_homepage_sale")
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function sale(Request $request)
    {
        if (!($request->isXmlHttpRequest() && $this->isTokenValid())) {
            return $this->json(['status' => 'NG'], 400);
        }

        // 週間の売上金額
        $toDate = Carbon::now();
        $fromDate = Carbon::today()->subWeek();
        $rawWeekly = $this->getData($fromDate, $toDate, 'Y/m/d');

        // 月間の売上金額
        $fromDate = Carbon::now()->startOfMonth();
        $rawMonthly = $this->getData($fromDate, $toDate, 'Y/m/d');

        // 年間の売上金額
        $fromDate = Carbon::now()->subYear()->startOfMonth();
        $rawYear = $this->getData($fromDate, $toDate, 'Y/m');

        $datas = [$rawWeekly, $rawMonthly, $rawYear];

        return $this->json($datas);
    }

    /**
     * パスワード変更画面
     *
     * @Route("/%eccube_admin_route%/change_password", name="admin_change_password")
     * @Template("@admin/change_password.twig")
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
     */
    public function changePassword(Request $request)
    {
        $builder = $this->formFactory
            ->createBuilder(ChangePasswordType::class);

        $event = new EventArgs(
            [
                'builder' => $builder,
            ],
            $request
        );
        $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIM_CHANGE_PASSWORD_INITIALIZE, $event);

        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Member = $this->getUser();
            $salt = $Member->getSalt();
            $password = $form->get('change_password')->getData();

            $encoder = $this->encoderFactory->getEncoder($Member);

            // 2系からのデータ移行でsaltがセットされていない場合はsaltを生成.
            if (empty($salt)) {
                $salt = $encoder->createSalt();
            }

            $password = $encoder->encodePassword($password, $salt);

            $Member
                ->setPassword($password)
                ->setSalt($salt);

            $this->memberRepository->save($Member);

            $event = new EventArgs(
                [
                    'form' => $form,
                    'Member' => $Member,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::ADMIN_ADMIN_CHANGE_PASSWORD_COMPLETE, $event);

            $this->addSuccess('admin.change_password.password_changed', 'admin');

            return $this->redirectToRoute('admin_change_password');
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * 在庫なし商品の検索結果を表示する.
     *
     * @Route("/%eccube_admin_route%/search_nonstock", name="admin_homepage_nonstock")
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function searchNonStockProducts(Request $request)
    {
        // 在庫なし商品の検索条件をセッションに付与し, 商品マスタへリダイレクトする.
        $searchData = [];
        $searchData['stock'] = [ProductStock::OUT_OF_STOCK];
        $session = $request->getSession();
        $session->set('eccube.admin.product.search', $searchData);

        return $this->redirectToRoute('admin_product_page', [
            'page_no' => 1,
        ]);
    }

    /**
     * 本会員の検索結果を表示する.
     *
     * @Route("/%eccube_admin_route%/search_customer", name="admin_homepage_customer")
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function searchCustomer(Request $request)
    {
        $searchData = [];
        $searchData['customer_status'] = [CustomerStatus::REGULAR];
        $session = $request->getSession();
        $session->set('eccube.admin.customer.search', $searchData);

        return $this->redirectToRoute('admin_customer_page', [
            'page_no' => 1,
        ]);
    }

    /**
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @param array $excludes
     *
     * @return null|Request
     */
    private function getOrderEachStatus(array $excludes)
    {
        $sql = 'SELECT
                    t1.order_status_id as status,
                    COUNT(t1.id) as count
                FROM
                    dtb_order t1
                WHERE
                    t1.order_status_id NOT IN (:excludes)
                GROUP BY
                    t1.order_status_id
                ORDER BY
                    t1.order_status_id';
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('status', 'status');
        $rsm->addScalarResult('count', 'count');
        $query = $this->entityManager->createNativeQuery($sql, $rsm);
        $query->setParameters([':excludes' => $excludes]);
        $result = $query->getResult();
        $orderArray = [];
        foreach ($result as $row) {
            $orderArray[$row['status']] = $row['count'];
        }

        return $orderArray;
    }

    /**
     * @param $dateTime
     *
     * @return array|mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function getSalesByDay($dateTime)
    {
        // concat... for pgsql
        // http://stackoverflow.com/questions/1091924/substr-does-not-work-with-datatype-timestamp-in-postgres-8-3
        $dql = 'SELECT
                  SUBSTRING(CONCAT(o.order_date, \'\'), 1, 10) AS order_day,
                  SUM(o.payment_total) AS order_amount,
                  COUNT(o) AS order_count
                FROM
                  Eccube\Entity\Order o
                WHERE
                    o.OrderStatus NOT IN (:excludes)
                    AND SUBSTRING(CONCAT(o.order_date, \'\'), 1, 10) = SUBSTRING(:targetDate, 1, 10)
                GROUP BY
                  order_day';

        $q = $this->entityManager
            ->createQuery($dql)
            ->setParameter(':excludes', $this->excludes)
            ->setParameter(':targetDate', $dateTime);

        $result = [];
        try {
            $result = $q->getSingleResult();
        } catch (NoResultException $e) {
            // 結果がない場合は空の配列を返す.
        }

        return $result;
    }

    /**
     * @param $dateTime
     *
     * @return array|mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function getSalesByMonth($dateTime)
    {
        // concat... for pgsql
        // http://stackoverflow.com/questions/1091924/substr-does-not-work-with-datatype-timestamp-in-postgres-8-3
        $dql = 'SELECT
                  SUBSTRING(CONCAT(o.order_date, \'\'), 1, 7) AS order_month,
                  SUM(o.payment_total) AS order_amount,
                  COUNT(o) AS order_count
                FROM
                  Eccube\Entity\Order o
                WHERE
                    o.OrderStatus NOT IN (:excludes)
                    AND SUBSTRING(CONCAT(o.order_date, \'\'), 1, 7) = SUBSTRING(:targetDate, 1, 7)
                GROUP BY
                  order_month';

        $q = $this->entityManager
            ->createQuery($dql)
            ->setParameter(':excludes', $this->excludes)
            ->setParameter(':targetDate', $dateTime);

        $result = [];
        try {
            $result = $q->getSingleResult();
        } catch (NoResultException $e) {
            // 結果がない場合は空の配列を返す.
        }

        return $result;
    }

    /**
     * 在庫切れ商品数を取得
     *
     * @return mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function countNonStockProducts()
    {
        $qb = $this->productRepository->createQueryBuilder('p')
            ->select('count(DISTINCT p.id)')
            ->innerJoin('p.ProductClasses', 'pc')
            ->where('pc.stock_unlimited = :StockUnlimited AND pc.stock = 0')
            ->setParameter('StockUnlimited', false);

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * 商品数を取得
     *
     * @return mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function countProducts()
    {
        $qb = $this->productRepository->createQueryBuilder('p')
            ->select('count(p.id)')
            ->where('p.Status in (:Status)')
            ->setParameter('Status', [ProductStatus::DISPLAY_SHOW, ProductStatus::DISPLAY_HIDE]);

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * 本会員数を取得
     *
     * @return mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function countCustomers()
    {
        $qb = $this->customerRepository->createQueryBuilder('c')
            ->select('count(c.id)')
            ->where('c.Status = :Status')
            ->setParameter('Status', CustomerStatus::REGULAR);

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * 期間指定のデータを取得
     *
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @param $format
     *
     * @return array
     */
    private function getData(Carbon $fromDate, Carbon $toDate, $format)
    {
        $qb = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.order_date >= :fromDate')
            ->andWhere('o.order_date <= :toDate')
            ->andWhere('o.OrderStatus NOT IN (:excludes)')
            ->setParameter(':excludes', $this->excludes)
            ->setParameter(':fromDate', $fromDate->copy())
            ->setParameter(':toDate', $toDate->copy())
            ->orderBy('o.order_date');

        $result = $qb->getQuery()->getResult();

        return $this->convert($result, $fromDate, $toDate, $format);
    }

    /**
     * 期間毎にデータをまとめる
     *
     * @param $result
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @param $format
     *
     * @return array
     */
    private function convert($result, Carbon $fromDate, Carbon $toDate, $format)
    {
        $raw = [];
        for ($date = $fromDate; $date <= $toDate; $date = $date->addDay()) {
            $raw[$date->format($format)]['price'] = 0;
            $raw[$date->format($format)]['count'] = 0;
        }

        foreach ($result as $Order) {
            $raw[$Order->getOrderDate()->format($format)]['price'] += $Order->getPaymentTotal();
            ++$raw[$Order->getOrderDate()->format($format)]['count'];
        }

        return $raw;
    }
}

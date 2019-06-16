<?php

namespace AppBundle\Service;

use AppBundle\Entity\Lids;
use AppBundle\Entity\LidsUserRatingStatus;
use AppBundle\Repository\LidsRepository;
use AppBundle\Repository\LidsUserRatingStatusRepository;
use Application\Sonata\UserBundle\Entity\User;
use Monolog\Logger;

class LidsService
{
    public const LID_READ_IN_EMAIL = 'email';

    /**
     * @var array
     */
    protected $aDefaultRating = [
        'В обработке' => '#a79a9a',
        'Спам' => '#696969',
        'Не удалось связаться' => '#b22222',
        'Не заинтересован в услуге' => '#dcdcdc',
        'Справочный вопрос' => '#bebebe',
        'Договорились о встрече' => '#00ff00',
        'Обработан' => '#32cd32',
        'Отправил письмо' => '#a79a9a',
        'Получил ответ на e-mail' => '#7166bf',
    ];

    /**
     * @var LidsRepository
     */
    private $lidsRepository;

    /**
     * @var LidsUserRatingStatusRepository
     */
    private $lidsUserRatingStatusRepository;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        LidsRepository $lidsRepository,
        LidsUserRatingStatusRepository $lidsUserRatingStatusRepository,
        Logger $logger
    ) {
        $this->lidsRepository = $lidsRepository;
        $this->lidsUserRatingStatusRepository = $lidsUserRatingStatusRepository;
        $this->logger = $logger;
    }

    public function getLid($id): ?Lids
    {
        return $this->lidsRepository->find($id);
    }

    public function save(Lids $lid)
    {
        $this->lidsRepository->save($lid);
    }

    /**
     * Получить количество не прочитанных лидов
     * @param User $user
     * @return int
     */
    public function getCountNoReadLids(User $user): int
    {
        try {
            return (int)$this->lidsRepository
                ->createQueryBuilder('l')
                ->select('COUNT(1)')
                ->where('l.user = :user')
                ->andWhere('l.isRead = false')
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

        } catch (\Exception $exception) {
            $this->logger->err($exception->getMessage());
        }

        return 0;
    }

    /**
     * Пометить лд почитанным
     * Не забудьте указать где прочтен лид до вызова этого метода
     * @param Lids $lids
     */
    public function setIsRead(Lids $lids)
    {
        if ($lids->isRead()) {
            return;
        }

        $lids->setIsRead(true);
        $lids->setReadDate(new \DateTime());
        $this->lidsRepository->save($lids);
    }

    /**
     * Сделать лиды прочитанными
     * @param array $ids
     */
    public function setIsReadLids(array $ids)
    {
        $this->lidsRepository->createQueryBuilder('l')->update()
            ->set('l.isRead', true)
            ->set('l.readDate', $this->lidsRepository->getExpressionBuilder()->literal(date('Y-m-d H:i:s')))
            ->where($this->lidsRepository->getExpressionBuilder()->in('l.idlids', $ids))
            ->andWhere('l.isRead = ?1')
            ->setParameter(1, false)
            ->getQuery()->execute();
    }

    /**
     * Создать статусы для нового пользователя
     * @param User $user
     */
    public function createDefaultStatusForUser(User $user)
    {
        $entities = [];

        foreach ($this->aDefaultRating as $name => $color) {
            $entity = new LidsUserRatingStatus();
            $entity->setName($name)
                ->setColor($color)
                ->setUser($user->getId());

            $entities[] = $entity;
        }

        try {
            $this->lidsUserRatingStatusRepository->saveAll($entities);
        } catch (\Exception $e) {
            $this->logger->error('Can not create user default status ', (array)$e);
        }
    }

    /**
     * Получить список статусов пользователя
     * @param User $user
     * @return array
     */
    public function getLidsStatusForUser(User $user): array
    {
        return $this->lidsUserRatingStatusRepository->findBy(['user' => $user->getId()]);
    }
}
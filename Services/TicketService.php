<?php

namespace Webkul\UVDesk\CoreBundle\Services;

use Doctrine\ORM\Query;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;
use Webkul\UVDesk\CoreBundle\Entity\Ticket;
use Webkul\UVDesk\CoreBundle\Entity\Thread;
use Symfony\Component\HttpFoundation\Response;
use Webkul\UVDesk\CoreBundle\Entity\Attachment;
use Symfony\Component\HttpFoundation\RequestStack;
use Webkul\UVDesk\CoreBundle\Utils\TokenGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TicketService
{
    protected $container;
	protected $requestStack;
    protected $entityManager;
    
    public function __construct(ContainerInterface $container, RequestStack $requestStack, EntityManager $entityManager)
    {
        $this->container = $container;
		$this->requestStack = $requestStack;
        $this->entityManager = $entityManager;
    }

    public function getRandomRefrenceId($email = null)
    {
        $email = !empty($email) ? $email : $this->container->getParameter('uvdesk.support_email.id');
        $emailDomain = substr($email, strpos($email, '@'));

        return sprintf("<%s%s>", TokenGenerator::generateToken(20, '0123456789abcdefghijklmnopqrstuvwxyz'), $emailDomain);
    }

    public function getUser() {
        return $this->container->get('user.service')->getCurrentUser();
    }

    public function getDefaultType()
    {
        $typeCode = $this->container->getParameter('uvdesk.default.ticket.type');
        $ticketType = $this->entityManager->getRepository('UVDeskCoreBundle:TicketType')->findOneByCode($typeCode);

        return !empty($ticketType) ? $ticketType : null;
    }

    public function getDefaultStatus()
    {
        $statusCode = $this->container->getParameter('uvdesk.default.ticket.status');
        $ticketStatus = $this->entityManager->getRepository('UVDeskCoreBundle:TicketStatus')->findOneByCode($statusCode);

        return !empty($ticketStatus) ? $ticketStatus : null;
    }

    public function getDefaultPriority()
    {
        $priorityCode = $this->container->getParameter('uvdesk.default.ticket.priority');
        $ticketPriority = $this->entityManager->getRepository('UVDeskCoreBundle:TicketPriority')->findOneByCode($priorityCode);

        return !empty($ticketPriority) ? $ticketPriority : null;
    }

    public function appendTwigSnippet($snippet = '')
    {
        switch ($snippet) {
            case 'createMemberTicket':
                return $this->getMemberCreateTicketSnippet();
                break;
            default:
                break;
        }

        return '';
    }

    public function getMemberCreateTicketSnippet()
    {
        $twigTemplatingEngine = $this->container->get('twig');
        $ticketTypeCollection = $this->entityManager->getRepository('UVDeskCoreBundle:TicketType')->findByIsActive(true);
        
        return $twigTemplatingEngine->render('@UVDeskCore/Snippets/createMemberTicket.html.twig', [
            'ticketTypeCollection' => $ticketTypeCollection
        ]);
    }

    public function createTicket(array $params = [])
    {
        $thread = $this->entityManager->getRepository('UVDeskCoreBundle:Thread')->findOneByMessageId($params['messageId']);

        if (empty($thread)) {
            $user = $this->entityManager->getRepository('UVDeskCoreBundle:User')->findOneByEmail($params['from']);

            if (empty($user) || null == $user->getCustomerInstance()) {
                $role = $this->entityManager->getRepository('UVDeskCoreBundle:SupportRole')->findOneByCode($params['role']);
                if (empty($role)) {
                    throw new \Exception("The requested role '" . $params['role'] . "' does not exist.");
                }
                
                // Create User Instance
                $user = $this->container->get('user.service')->createUserInstance($params['from'], $params['name'], $role, [
                    'source' => strtolower($params['source']),
                    'active' => true,
                ]);
            }

            $params['role'] = 4;
            $params['mailboxEmail'] = current($params['replyTo']); 
            $params['customer'] = $params['user'] = $user;

            return $this->createTicketBase($params);
        }

        return;
    }

    public function createTicketBase(array $ticketData = [])
    {
        if ('email' == $ticketData['source']) {
            try {
                if (array_key_exists('UVDeskMailboxBundle', $this->container->getParameter('kernel.bundles'))) {
                    $mailbox = $this->container->get('uvdesk.mailbox')->getMailboxByEmail($ticketData['mailboxEmail']);
                    $ticketData['mailboxEmail'] = $mailbox['email'];
                }
            } catch (\Exception $e) {
                // No mailbox found for this email. Skip ticket creation.
                return;
            }
        }

        // Set Defaults
        $ticketType = !empty($ticketData['type']) ? $ticketData['type'] : $this->getDefaultType();
        $ticketStatus = !empty($ticketData['status']) ? $ticketData['status'] : $this->getDefaultStatus();
        $ticketPriority = !empty($ticketData['priority']) ? $ticketData['priority'] : $this->getDefaultPriority();
        $ticketMessageId = 'email' == $ticketData['source'] ? (!empty($ticketData['messageId']) ? $ticketData['messageId'] : null) : $this->getRandomRefrenceId();

        $ticketData['type'] = $ticketType;
        $ticketData['status'] = $ticketStatus;
        $ticketData['priority'] = $ticketPriority;
        $ticketData['messageId'] = $ticketMessageId;
        $ticketData['isTrashed'] = false;

        $ticket = new Ticket();
        foreach ($ticketData as $property => $value) {
            $callable = 'set' . ucwords($property);

            if (method_exists($ticket, $callable)) {
                $ticket->$callable($value);
            }
        }

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $this->createThread($ticket, $ticketData);
    }
    
    public function createThread(Ticket $ticket, array $threadData)
    {
        $threadData['isLocked'] = 0;

        if ('forward' === $threadData['threadType']) {
            $threadData['replyTo'] = $threadData['to'];
        }
        
        $collaboratorEmails = array_merge(!empty($threadData['cccol']) ? $threadData['cccol'] : [], !empty($threadData['cc']) ? $threadData['cc'] : []);
        if (!empty($collaboratorEmails)) {
            $threadData['cc'] = $collaboratorEmails;
        }
                
        $thread = new Thread();
        $thread->setTicket($ticket);
        $thread->setCreatedAt(new \DateTime());
        $thread->setUpdatedAt(new \DateTime());

        foreach ($threadData as $property => $value) {
            if (!empty($value)) {
                $callable = 'set' . ucwords($property);
                
                if (method_exists($thread, $callable)) {
                    $thread->$callable($value);
                }
            }
        }

        // Update ticket reference ids is thread message id is defined
        if (null != $thread->getMessageId() && false === strpos($ticket->getReferenceIds(), $thread->getMessageId())) {
            $updatedReferenceIds = $ticket->getReferenceIds() . ' ' . $thread->getMessageId();            
            $ticket->setReferenceIds($updatedReferenceIds);

            $this->entityManager->persist($ticket);
        }

        if ('reply' === $threadData['threadType']) {
            if ('agent' === $threadData['createdBy']) {
                // Ticket has been updated by support agents, mark as agent replied | customer view pending
                $ticket->setIsCustomerViewed(false);
                $ticket->setIsReplied(true);
            } else {
                // Ticket has been updated by customer, mark as agent view | reply pending
                $ticket->setIsAgentViewed(false);
                $ticket->setIsReplied(false);
            }

            $this->entityManager->persist($ticket);
        } else if ('create' === $threadData['threadType']) {
            $ticket->setIsReplied(false);
            $this->entityManager->persist($ticket);
        }
        
        $ticket->currentThread = $this->entityManager->getRepository('UVDeskCoreBundle:Thread')->getTicketCurrentThread($ticket);
        
        $this->entityManager->persist($thread);
        $this->entityManager->flush();
        
        $ticket->createdThread = $thread;

        // Uploading Attachments
        if (!empty($threadData['attachments'])) {
            if ('email' == $threadData['source']) {
                $this->saveThreadEmailAttachments($thread, $threadData['attachments']);
            } else if (!empty($threadData['attachments'])) {
                $this->saveThreadAttachment($thread, $threadData['attachments']);
            }
        }

        return $thread;
    }

    public function saveThreadAttachment($thread, array $attachments)
    {
        $prefix = 'threads/' . $thread->getId();
        $uploadManager = $this->container->get('uvdesk.core.file_system.service')->getUploadManager();

        foreach ($attachments as $attachment) {
            $uploadedFileAttributes = $uploadManager->uploadFile($attachment, $prefix);

            if (!empty($uploadedFileAttributes['path'])) {
                ($threadAttachment = new Attachment())
                    ->setThread($thread)
                    ->setName($uploadedFileAttributes['name'])
                    ->setPath($uploadedFileAttributes['path'])
                    ->setSize($uploadedFileAttributes['size'])
                    ->setContentType($uploadedFileAttributes['content-type']);
                
                $this->entityManager->persist($threadAttachment);
            }
        }

        $this->entityManager->flush();
    }

    public function saveThreadEmailAttachments($thread, array $attachments)
    {
        $prefix = 'threads/' . $thread->getId();
        $uploadManager = $this->container->get('uvdesk.core.file_system.service')->getUploadManager();
        
        // Upload thread attachments
        foreach ($attachments as $attachment) {
            $uploadedFileAttributes = $uploadManager->uploadEmailAttachment($attachment, $prefix);
            
            if (!empty($uploadedFileAttributes['path'])) {
                ($threadAttachment = new Attachment())
                    ->setThread($thread)
                    ->setName($uploadedFileAttributes['name'])
                    ->setPath($uploadedFileAttributes['path'])
                    ->setSize($uploadedFileAttributes['size'])
                    ->setContentType($uploadedFileAttributes['content-type']);
                
                $this->entityManager->persist($threadAttachment);
            }
        }

        $this->entityManager->flush();
    }

    public function getTypes()
    {
        static $types;
        if (null !== $types)
            return $types;

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tp.id','tp.code As name')->from('UVDeskCoreBundle:TicketType', 'tp')
                ->andwhere('tp.isActive = 1');

        return $types = $qb->getQuery()->getArrayResult();
    }

    public function getStatus()
    {
        static $statuses;
        if (null !== $statuses)
            return $statuses;

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ts')->from('UVDeskCoreBundle:TicketStatus', 'ts');
        // $qb->orderBy('ts.sortOrder', Criteria::ASC);

        return $statuses = $qb->getQuery()->getArrayResult();
    }

    public function getTicketTotalThreads($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(th.id) as threadCount')->from('UVDeskCoreBundle:Ticket', 't')
            ->leftJoin('t.threads', 'th')
            ->andWhere('t.id = :ticketId')
            ->andWhere('th.threadType = :threadType')
            ->setParameter('threadType','reply')
            ->setParameter('ticketId', $ticketId);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(t.id) as threadCount')->from('UVDeskCoreBundle:Thread', 't')
            ->andWhere('t.ticket = :ticketId')
            ->andWhere('t.threadType = :threadType')
            ->setParameter('threadType','reply')
            ->setParameter('ticketId', $ticketId);

        return $qb->getQuery()->getSingleScalarResult();
    }

    public function getTicketTags($request = null)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tg')->from('UVDeskCoreBundle:Tag', 'tg');

        if($request) {
            $qb->andwhere("tg.name LIKE :tagName");
            $qb->setParameter('tagName', '%'.urldecode($request->query->get('query')).'%');
            $qb->andwhere("tg.id NOT IN (:ids)");
            $qb->setParameter('ids', explode(',',urldecode($request->query->get('not'))));
        }

        return $qb->getQuery()->getArrayResult();
    }
    
    public function paginateMembersTicketCollection(Request $request)
    {
        $params = $request->query->all();
        $activeUser = $this->container->get('user.service')->getSessionUser();
        $ticketRepository = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket');

        // Get base query
        $baseQuery = $ticketRepository->prepareBaseTicketQuery($activeUser, $params);
        $ticketTabs = $ticketRepository->getTicketTabDetails($activeUser, $params);

        // Add reply count filter to base query
        if (array_key_exists('repliesLess', $params) || array_key_exists('repliesMore', $params)) {
            $baseQuery->leftJoin('ticket.threads', 'th')
                ->andWhere('th.threadType = :threadType')->setParameter('threadType', 'reply')
                ->groupBy('ticket.id');

            if (array_key_exists('repliesLess', $params)) {
                $baseQuery->andHaving('count(th.id) < :threadValueLesser')->setParameter('threadValueLesser', intval($params['repliesLess']));
            }

            if (array_key_exists('repliesMore', $params)) {
                $baseQuery->andHaving('count(th.id) > :threadValueGreater')->setParameter('threadValueGreater', intval($params['repliesMore']));
            }
        }

        // Apply Pagination
        $pageNumber = !empty($params['page']) ? (int) $params['page'] : 1;
        $itemsLimit = !empty($params['limit']) ? (int) $params['limit'] : $ticketRepository::DEFAULT_PAGINATION_LIMIT;

        if (isset($params['repliesLess']) || isset($params['repliesMore'])) {
            $paginationOptions = ['wrap-queries' => true];
            $paginationQuery = $baseQuery->getQuery()
                ->setHydrationMode(Query::HYDRATE_ARRAY);
        } else {
            $paginationOptions = ['distinct' => true];
            $paginationQuery = $baseQuery->getQuery()
                ->setHydrationMode(Query::HYDRATE_ARRAY)
                ->setHint('knp_paginator.count', isset($params['status']) ? $ticketTabs[$params['status']] : $ticketTabs[1]);
        }

        $pagination = $this->container->get('knp_paginator')->paginate($paginationQuery, $pageNumber, $itemsLimit, $paginationOptions);
        // Process Pagination Response
        $ticketCollection = [];
        $paginationParams = $pagination->getParams();
        $paginationData = $pagination->getPaginationData();

        $paginationParams['page'] = 'replacePage';
        $paginationData['url'] = '#' . $this->container->get('uvdesk.service')->buildPaginationQuery($paginationParams);
        // $container->get('default.service')->buildSessionUrl('ticket',$queryParameters);


        $ticketThreadCountQueryTemplate = $this->entityManager->createQueryBuilder()
            ->select('COUNT(thread.id) as threadCount')
            ->from('UVDeskCoreBundle:Ticket', 'ticket')
            ->leftJoin('ticket.threads', 'thread')
            ->where('ticket.id = :ticketId')
            ->andWhere('thread.threadType = :threadType')->setParameter('threadType', 'reply');
        
        // $ticketAttachmentCountQueryTemplate = $this->entityManager->createQueryBuilder()
        //     ->select('DISTINCT COUNT(attachment.id) as attachmentCount')
        //     ->from('UVDeskCoreBundle:Thread', 'thread')
        //     ->leftJoin('thread.ticket', 'ticket')
        //     ->leftJoin('thread.attachments', 'attachment')
        //     ->andWhere('ticket.id = :ticketId');
        
        foreach ($pagination->getItems() as $ticketDetails) {
            $ticket = array_shift($ticketDetails);

            $ticketThreadCountQuery = clone $ticketThreadCountQueryTemplate;
            $ticketThreadCountQuery->setParameter('ticketId', $ticket['id']);

            // $ticketAttachmentCountQuery = clone $ticketAttachmentCountQueryTemplate;
            // $ticketAttachmentCountQuery->setParameter('ticketId', $ticket['id']);

            $totalTicketReplies = (int) $ticketThreadCountQuery->getQuery()->getSingleScalarResult();
            // $ticketHasAttachments = (bool) (int) $ticketAttachmentCountQuery->getQuery()->getSingleScalarResult();
            $ticketHasAttachments = false;
          
            $ticketResponse = [
                'id' => $ticket['id'],
                'subject' => $ticket['subject'],
                'isStarred' => $ticket['isStarred'],
                'isAgentView' => $ticket['isAgentViewed'],
                'isTrashed' => $ticket['isTrashed'],
                'source' => $ticket['source'],
                'group' => $ticketDetails['groupName'],
                'team' => $ticketDetails['teamName'],
                'priority' => $ticket['priority'],
                'type' => $ticketDetails['typeName'],
                'timestamp' => $ticket['createdAt']->getTimestamp(),
                'formatedCreatedAt' => $ticket['createdAt']->format('d-m-Y h:ia'),
                'totalThreads' => $totalTicketReplies,
                'agent' => null,
                'customer' => null,
                'hasAttachments' => $ticketHasAttachments
            ];
           
            if (!empty($ticketDetails['agentId'])) {
                $ticketResponse['agent'] = [
                    'id' => $ticketDetails['agentId'],
                    'name' => $ticketDetails['agentName'],
                    'smallThumbnail' => $ticketDetails['smallThumbnail'],
                ];
            }

            if (!empty($ticketDetails['customerId'])) {
                $ticketResponse['customer'] = [
                    'id' => $ticketDetails['customerId'],
                    'name' => $ticketDetails['customerName'],
                    'email' => $ticketDetails['customerEmail'],
                    'smallThumbnail' => $ticketDetails['customersmallThumbnail'],
                ];
            }

            array_push($ticketCollection, $ticketResponse);
        }
         
        return [
            'tickets' => $ticketCollection,
            'pagination' => $paginationData,
            'tabs' => $ticketTabs,
            'labels' => [
                'predefind' => $this->getPredefindLabelDetails($this->container, $params),
                'custom' => $this->getCustomLabelDetails($this->container),
            ],
          
        ];
    }
    
    public function getPredefindLabelDetails($container, $params)
    {
        $currentUser = $container->get('user.service')->getCurrentUser();
        $data = array();
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $ticketRepository = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket');
        $queryBuilder->select('COUNT(DISTINCT ticket.id) as ticketCount')->from('UVDeskCoreBundle:Ticket', 'ticket')
            ->leftJoin('ticket.agent', 'agent');
        
        if ($currentUser->getRoles()[0] != 'ROLE_SUPER_ADMIN') {
            $queryBuilder->andwhere('agent = ' . $currentUser->getId());
        }
        
        $queryBuilder->andwhere('ticket.isTrashed != 1');

        // for all tickets count
        $data['all'] = $queryBuilder->getQuery()->getSingleScalarResult();

        // for new tickets count
        $newQb = clone $queryBuilder;
        $newQb->andwhere('ticket.isNew = 1');
        $data['new'] = $newQb->getQuery()->getSingleScalarResult();

        // for unassigned tickets count
        $unassignedQb = clone $queryBuilder;
        $unassignedQb->andwhere("ticket.agent is NULL");
        $data['unassigned'] = $unassignedQb->getQuery()->getSingleScalarResult();

        // for unanswered ticket count
        $unansweredQb = clone $queryBuilder;
        $unansweredQb->andwhere('ticket.isReplied = 0');
        $data['notreplied'] = $unansweredQb->getQuery()->getSingleScalarResult();

        // for my tickets count
        $mineQb = clone $queryBuilder;
        $mineQb->andWhere("agent = :agentId")
                ->setParameter('agentId', $currentUser->getId());
        $data['mine'] = $mineQb->getQuery()->getSingleScalarResult();

        // for starred tickets count
        $starredQb = clone $queryBuilder;
        $starredQb->andwhere('ticket.isStarred = 1');
        $data['starred'] = $starredQb->getQuery()->getSingleScalarResult();

        // for trashed tickets count
        $trashedQb = clone $queryBuilder;
        $trashedQb->andwhere('ticket.isTrashed = 1');
        $data['trashed'] = $trashedQb->getQuery()->getSingleScalarResult();

        return $data;
    }

    public function paginateMembersTicketThreadCollection(Ticket $ticket, Request $request)
    {
        $params = $request->query->all();
        $activeUser = $this->container->get('user.service')->getSessionUser();
        $threadRepository = $this->entityManager->getRepository('UVDeskCoreBundle:Thread');
        $uvdeskFileSystemService = $this->container->get('uvdesk.core.file_system.service');

        // Get base query
        $enableLockedThreads = $this->container->get('user.service')->isAccessAuthorized('ROLE_AGENT_MANAGE_LOCK_AND_UNLOCK_THREAD');
        $baseQuery = $threadRepository->prepareBasePaginationRecentThreadsQuery($ticket, $params, $enableLockedThreads);
        
        // Apply Pagination
        $paginationItemsQuery = clone $baseQuery;
        $totalPaginationItems = $paginationItemsQuery->select('COUNT(DISTINCT thread.id)')->getQuery()->getSingleScalarResult();
        
        $pageNumber = !empty($params['page']) ? (int) $params['page'] : 1;
        $itemsLimit = !empty($params['limit']) ? (int) $params['limit'] : $threadRepository::DEFAULT_PAGINATION_LIMIT;
        
        $paginationOptions = ['distinct' => true];
        $paginationQuery = $baseQuery->getQuery()->setHydrationMode(Query::HYDRATE_ARRAY)->setHint('knp_paginator.count', (int) $totalPaginationItems);

        $pagination = $this->container->get('knp_paginator')->paginate($paginationQuery, $pageNumber, $itemsLimit, $paginationOptions);

        // Process Pagination Response
        $threadCollection = [];
        $paginationParams = $pagination->getParams();
        $paginationData = $pagination->getPaginationData();

        if (!empty($params['threadRequestedId'])) {
            $requestedThreadCollection = $baseQuery
                ->andWhere('thread.id >= :threadRequestedId')->setParameter('threadRequestedId', (int) $params['threadRequestedId'])
                ->getQuery()->getArrayResult();
            
            $totalRequestedThreads = count($requestedThreadCollection);
            $paginationData['current'] = ceil($totalRequestedThreads / $threadRepository::DEFAULT_PAGINATION_LIMIT);

            if ($paginationData['current'] > 1) {
                $paginationData['firstItemNumber'] = 1;
                $paginationData['lastItemNumber'] = $totalRequestedThreads;
                $paginationData['next'] = ceil(($totalRequestedThreads + 1) / $threadRepository::DEFAULT_PAGINATION_LIMIT);
            }
        }

        $paginationParams['page'] = 'replacePage';
        $paginationData['url'] = '#' . $this->container->get('uvdesk.service')->buildPaginationQuery($paginationParams);
      
        foreach ($pagination->getItems() as $threadDetails) {
            $threadResponse = [
                'id' => $threadDetails['id'],
                'user' => null,
                'fullname' => null,
				'reply' => html_entity_decode($threadDetails['message']),
				'source' => $threadDetails['source'],
                'threadType' => $threadDetails['threadType'],
                'userType' => $threadDetails['createdBy'],
                'timestamp' => $threadDetails['createdAt']->getTimestamp(),
                'formatedCreatedAt' => $threadDetails['createdAt']->format('d-m-Y h:ia'),
                'bookmark' => $threadDetails['isBookmarked'],
                'isLocked' => $threadDetails['isLocked'],
                'replyTo' => $threadDetails['replyTo'],
                'cc' => $threadDetails['cc'],
                'bcc' => $threadDetails['bcc'],
                'attachments' => $threadDetails['attachments'],
            ];

            if (!empty($threadDetails['user'])) {
                $threadResponse['fullname'] = trim($threadDetails['user']['firstName'] . ' ' . $threadDetails['user']['lastName']);
                $threadResponse['user'] = [
                    'id' => $threadDetails['user']['id'],
                    'name' => $threadResponse['fullname'],
                ];
            }

            if (!empty($threadResponse['attachments'])) {
                $resolvedAttachmentAttributesCollection = [];

                foreach ($threadResponse['attachments'] as $attachment) {
                    $attachmentReferenceObject = $this->entityManager->getReference(Attachment::class, $attachment['id']);
                    $resolvedAttachmentAttributesCollection[] = $uvdeskFileSystemService->getFileTypeAssociations($attachmentReferenceObject);
                }

                $threadResponse['attachments'] = $resolvedAttachmentAttributesCollection;
            }

            array_push($threadCollection, $threadResponse);
        }

        return [
            'threads' => $threadCollection,
            'pagination' => $paginationData,
        ];
    }

    public function massXhrUpdate(Request $request)
    {
        $permissionMessages = [
            'trashed' => ['permission' => 'ROLE_AGENT_DELETE_TICKET', 'message' => 'Success ! Tickets moved to trashed successfully.'],
            'delete' => ['permission' =>  'ROLE_AGENT_DELETE_TICKET', 'message' => 'Success ! Tickets removed successfully.'],
            'restored' => ['permission' =>  'ROLE_AGENT_RESTORE_TICKET', 'message' => 'Success ! Tickets restored successfully.'],
            'agent' => ['permission' =>  'ROLE_AGENT_ASSIGN_TICKET', 'message' => 'Success ! Agent assigned successfully.'],
            'status' => ['permission' =>  'ROLE_AGENT_UPDATE_TICKET_STATUS', 'message' => 'Success ! Tickets status updated successfully.'],
            'type' => ['permission' =>  'ROLE_AGENT_ASSIGN_TICKET_TYPE', 'message' => 'Success ! Tickets type updated successfully.'],
            'group' => ['permission' =>  'ROLE_AGENT_ASSIGN_TICKET_GROUP', 'message' => 'Success ! Tickets group updated successfully.'],
            'team' => ['permission' =>  'ROLE_AGENT_ASSIGN_TICKET_GROUP', 'message' => 'Success ! Tickets team updated successfully.'],
            'priority' => ['permission' =>  'ROLE_AGENT_UPDATE_TICKET_PRIORITY', 'message' => 'Success ! Tickets priority updated successfully.'],
            'label' => ['permission' =>  '', 'message' => 'Success ! Tickets added to label successfully.']
        ];
        $json = array();
        $data = $request->request->get('data');
        
        $ids = $data['ids'];        
        foreach ($ids as $id) {
            $ticket = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket')->find($id);
            if(!$ticket)
                continue;

            switch($data['actionType']) {
                case 'trashed':
                    $ticket->setIsTrashed(1);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();                  
                    break;
                case 'delete':

                    $this->entityManager->remove($ticket);
                    $this->entityManager->flush();
                    break;
                case 'restored':
                    $ticket->setIsTrashed(0);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                   
                    break;
                case 'agent':
                    $flag = 0;
                    $agent = $this->entityManager->getRepository('UVDeskCoreBundle:User')->find($data['targetId']);
                    $targetAgent = $agent->getUserInstance()['agent'] ? $agent->getUserInstance()['agent']->getName() : 'UnAssigned';
                    if($ticket->getAgent() != $agent) {
                        $ticketAgent = $ticket->getAgent();
                        $currentAgent = $ticketAgent ? ($ticketAgent->getUserInstance()['agent'] ? $ticketAgent->getUserInstance()['agent']->getName() : 'UnAssigned') : 'UnAssigned';

                        $notePlaceholders = $this->getNotePlaceholderValues(
                                $currentAgent,
                                $targetAgent,
                                'agent'
                            );
                        $flag = 1;
                    }

                    $ticket->setAgent($agent);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                    break;
                case 'status':
                    $status = $this->entityManager->getRepository('UVDeskCoreBundle:TicketStatus')->find($data['targetId']);
                    $flag = 0;
                    // dump($ticket->getStatus());die;
                    if($ticket->getStatus() != $status) {
                        $notePlaceholders = $this->getNotePlaceholderValues(
                                $ticket->getStatus()->getCode(),
                                $status->getCode(),
                                'status'
                            );
                        $flag = 1;
                    }
                    $ticket->setStatus($status);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                    break;
                case 'type':
                    $type = $this->entityManager->getRepository('UVDeskCoreBundle:TicketType')->find($data['targetId']);
                    $flag = 0;
                    if($ticket->getType() != $type) {
                        $notePlaceholders = $this->getNotePlaceholderValues(
                                $ticket->getType() ? $ticket->getType()->getCode() :'UnAssigned',
                                $type->getCode(),
                                'status'
                            );
                        $flag = 1;
                    }
                    $ticket->setType($type);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                    break;
                case 'group':
                    $group = $this->entityManager->getRepository('UVDeskCoreBundle:SupportGroup')->find($data['targetId']);
                    $flag = 0;
                    if($ticket->getSupportGroup() != $group) {
                        $notePlaceholders = $this->getNotePlaceholderValues(
                                    $ticket->getSupportGroup() ? $ticket->getSupportGroup()->getName() : 'UnAssigned',
                                    $group ? $group->getName() :'UnAssigned',
                                    'group'
                                );
                        $flag = 1;
                    }
                    $ticket->setSupportGroup($group);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                    break;
                case 'team':
                    $team = $this->entityManager->getRepository('UVDeskCoreBundle:SupportTeam')->find($data['targetId']);
                    $flag = 0;
                    if($ticket->getSupportTeam() != $team){
                        $notePlaceholders = $this->getNotePlaceholderValues(
                                $ticket->getSupportTeam() ? $ticket->getSupportTeam()->getName() :'UnAssigned',
                                $team ? $team->getName() :'UnAssigned',
                                'team'
                            );
                        $flag = 1;
                    }
                    $ticket->setSupportTeam($team);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();
                    break;
                case 'priority':
                    $flag = 0;
                    $priority = $this->entityManager->getRepository('UVDeskCoreBundle:TicketPriority')->find($data['targetId']);
                   
                    if($ticket->getPriority() != $priority) {
                        $notePlaceholders = $this->getNotePlaceholderValues(
                                    $ticket->getPriority()->getCode(),
                                    $priority->getCode(),
                                    'priority'
                                );
                        $flag = 1;
                    }
                    $ticket->setPriority($priority);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();

                    
                    break;
                case 'label':
                    $label = $this->entityManager->getRepository('UVDeskCoreBundle:SupportLabel')->find($data['targetId']);
                    if($label && !$this->entityManager->getRepository('UVDeskCoreBundle:Ticket')->isLabelAlreadyAdded($ticket, $label))
                        $ticket->addSupportLabel($label);
                    $this->entityManager->persist($ticket);
                    $this->entityManager->flush();
                    break;
            }
        }
        return [
            'alertClass' => 'success',
            'alertMessage' => $permissionMessages[$data['actionType']]['message'],
        ];
    }

    public function getNotePlaceholderValues($currentProperty,$targetProperty,$type = "", $details = null)
    {
        $variables = array();

        $variables['type.previousType'] = ($type == 'type') ? $currentProperty : '';
        $variables['type.updatedType'] = ($type == 'type') ? $targetProperty : '';

        $variables['status.previousStatus'] = ($type == 'status') ? $currentProperty : '';
        $variables['status.updatedStatus'] = ($type == 'status') ? $targetProperty : '';

        $variables['group.previousGroup'] = ($type == 'group') ? $currentProperty : '';
        $variables['group.updatedGroup'] = ($type == 'group') ? $targetProperty : '';

        $variables['team.previousTeam'] = ($type == 'team') ? $currentProperty : '';
        $variables['team.updatedTeam'] = ($type == 'team') ? $targetProperty : '';

        $variables['priority.previousPriority'] = ($type == 'priority') ? $currentProperty : '';
        $variables['priority.updatedPriority'] = ($type == 'priority') ? $targetProperty : '';

        $variables['agent.previousAgent'] = ($type == 'agent') ? $currentProperty : '';
        $variables['agent.updatedAgent'] = ($type == 'agent') ? $targetProperty : '';

        if($details) {
            $variables['agent.responsePerformingAgent'] = $details;
        } else {
            $detail = $this->getUser()->getUserInstance();
            $variables['agent.responsePerformingAgent'] = !empty($detail['agent']) ? $detail['agent']->getName() : '';
        }
        return $variables;
    }

    public function paginateMembersTicketTypeCollection(Request $request)
    {
        // Get base query
        $params = $request->query->all();
        $ticketRepository = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket');
        $paginationQuery = $ticketRepository->prepareBasePaginationTicketTypesQuery($params);

        // Apply Pagination
        $paginationOptions = ['distinct' => true];
        $pageNumber = !empty($params['page']) ? (int) $params['page'] : 1;
        $itemsLimit = !empty($params['limit']) ? (int) $params['limit'] : $ticketRepository::DEFAULT_PAGINATION_LIMIT;

        $pagination = $this->container->get('knp_paginator')->paginate($paginationQuery, $pageNumber, $itemsLimit, $paginationOptions);

        // Process Pagination Response
        $paginationParams = $pagination->getParams();
        $paginationData = $pagination->getPaginationData();

        $paginationParams['page'] = 'replacePage';
        $paginationData['url'] = '#' . $this->container->get('uvdesk.service')->buildPaginationQuery($paginationParams);

        return [
            'types' => array_map(function ($ticketType) {
                return [
                    'id' => $ticketType->getId(),
                    'code' => strtoupper($ticketType->getCode()),
                    'description' => $ticketType->getDescription(),
                    'isActive' => $ticketType->getIsActive(),
                ];
            }, $pagination->getItems()),
            'pagination_data' => $paginationData,
        ];
    }

    public function paginateMembersTagCollection(Request $request)
    {
        // Get base query
        $params = $request->query->all();
        $ticketRepository = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket');
        $baseQuery = $ticketRepository->prepareBasePaginationTagsQuery($params);
        
        // Apply Pagination
        $paginationResultsQuery = clone $baseQuery;
        $paginationResultsQuery->select('COUNT(supportTag.id)');
        $paginationQuery = $baseQuery->getQuery()->setHydrationMode(Query::HYDRATE_ARRAY)->setHint('knp_paginator.count', $paginationResultsQuery->getQuery()->getResult());

        $paginationOptions = ['distinct' => true];
        $pageNumber = !empty($params['page']) ? (int) $params['page'] : 1;
        $itemsLimit = !empty($params['limit']) ? (int) $params['limit'] : $ticketRepository::DEFAULT_PAGINATION_LIMIT;

        $pagination = $this->container->get('knp_paginator')->paginate($paginationQuery, $pageNumber, $itemsLimit, $paginationOptions);

        // Process Pagination Response
        $paginationParams = $pagination->getParams();
        $paginationData = $pagination->getPaginationData();

        $paginationParams['page'] = 'replacePage';
        $paginationData['url'] = '#' . $this->container->get('uvdesk.service')->buildPaginationQuery($paginationParams);

        return [
            'tags' => array_map(function ($supportTag) {
                return [
                    'id' => $supportTag['id'],
                    'name' => $supportTag['name'],
                    'ticketCount' => $supportTag['totalTickets'],
                    'articleCount' => 0,
                ];
            }, $pagination->getItems()),
            'pagination_data' => $paginationData,
        ];
    }

    public function getTicketInitialThreadDetails(Ticket $ticket)
    {
        $initialThread = $this->entityManager->getRepository('UVDeskCoreBundle:Thread')->findOneBy([
            'ticket' => $ticket,
            'threadType' => 'create',
        ]);

        if (!empty($initialThread)) {
            $author = $initialThread->getUser();
            $authorInstance = 'agent' == $initialThread->getCreatedBy() ? $author->getAgentInstance() : $author->getCustomerInstance();
        
            $threadDetails = [
                'id' => $initialThread->getId(),
                'source' => $initialThread->getSource(),
                'messageId' => $initialThread->getMessageId(),
                'threadType' => $initialThread->getThreadType(),
                'createdBy' => $initialThread->getCreatedBy(),
                'message' => html_entity_decode($initialThread->getMessage()),
                'attachments' => $initialThread->getAttachments(),
                'timestamp' => $initialThread->getCreatedAt()->getTimestamp(),
                'createdAt' => $initialThread->getCreatedAt()->format('d-m-Y h:ia'),
                'user' => $authorInstance->getPartialDetails(),
            ];

            $attachments = $threadDetails['attachments']->getValues();

            if (!empty($attachments)) {
                $resolvedAttachmentAttributesCollection = [];
                $uvdeskFileSystemService = $this->container->get('uvdesk.core.file_system.service');

                foreach ($attachments as $attachment) {
                    $resolvedAttachmentAttributesCollection[] = $uvdeskFileSystemService->getFileTypeAssociations($attachment);
                }

                $threadDetails['attachments'] = $resolvedAttachmentAttributesCollection;
            }
        }

        return $threadDetails ?? null;
    }

    public function getCreateReply($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $uvdeskFileSystemService = $this->container->get('uvdesk.core.file_system.service');
        
        $qb->select("th,a,u.id as userId")->from('UVDeskCoreBundle:Thread', 'th')
                ->leftJoin('th.ticket','t')
                ->leftJoin('th.attachments', 'a')
                ->leftJoin('th.user','u')
                ->andWhere('t.id = :ticketId')
                ->andWhere('th.threadType = :threadType')
                ->setParameter('threadType','create')
                ->setParameter('ticketId',$ticketId)
                ->orderBy('th.id', 'DESC')
                ->getMaxResults(1);

        $threadResponse = $qb->getQuery()->getArrayResult();

        if((!empty($threadResponse[0][0]))) {
            $threadDetails = $threadResponse[0][0];
            $userService = $this->container->get('user.service');
            
            if ($threadDetails['createdBy'] == 'agent') {
                $threadDetails['user'] = $userService->getAgentDetailById($threadResponse[0]['userId']);
            } else {
                $threadDetails['user'] = $userService->getCustomerPartialDetailById($threadResponse[0]['userId']);
            }
            
            $threadDetails['reply'] = html_entity_decode($threadDetails['message']);
            $threadDetails['formatedCreatedAt'] = $threadDetails['createdAt']->format('d-m-Y h:ia');
            $threadDetails['timestamp'] = $userService->convertToDatetimeTimezoneTimestamp($threadDetails['createdAt']);
        
            if (!empty($threadDetails['attachments'])) {
                $resolvedAttachmentAttributesCollection = [];

                foreach ($threadDetails['attachments'] as $attachment) {
                    $attachmentReferenceObject = $this->entityManager->getReference(Attachment::class, $attachment['id']);
                    $resolvedAttachmentAttributesCollection[] = $uvdeskFileSystemService->getFileTypeAssociations($attachmentReferenceObject);
                }

                $threadDetails['attachments'] = $resolvedAttachmentAttributesCollection;
            }
        }
        
        return $threadDetails ?? null;
    }

    public function hasAttachments($ticketId) {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select("DISTINCT COUNT(a.id) as attachmentCount")->from('UVDeskCoreBundle:Thread', 'th')
                ->leftJoin('th.ticket','t')
                ->leftJoin('th.attachments','a')
                ->andWhere('t.id = :ticketId')
                ->setParameter('ticketId',$ticketId);

        return intval($qb->getQuery()->getSingleScalarResult());
    }

    public function getAgentDraftReply($ticketId, $draftType)
    {
        return '';
        // $userId = $this->getUser()->getId();
        // $companyId = $this->getCompany()->getId();
        // $qb = $this->em->createQueryBuilder();
        // $qb->select('d')->from("UVDeskCoreBundle:Draft", 'd')
        //         ->andwhere('d.ticket = :ticketId')
        //         ->andwhere("d.field = '".$draftType."'")
        //         ->andwhere('d.user = :userId')
        //         ->andwhere("d.userType = 'agent'")
        //         ->setParameter('ticketId',$ticketId)
        //         ->setParameter('userId', $this->getUser()->getId());

        // $result = $qb->getQuery()->getOneOrNullResult();

        // if($result && trim(strip_tags($result->getContent())) ) {
        //     return $result->getContent();
        // }

        // $data = $this->container->get('user.service')->getUserDetailById($userId,$companyId);

        // return str_replace( "\n", '<br/>',$data->getSignature());
    }

    public function trans($text)
    {
        return $this->container->get('translator')->trans($text);
    }

    public function getAllSources()
    {
        $sources = ['email' => 'Email', 'website' => 'Website'];
        return $sources;
    }

    public function getCustomLabelDetails($container)
    {
        $currentUser = $container->get('user.service')->getCurrentUser();

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT t) as ticketCount,sl.id')->from("UVDeskCoreBundle:Ticket", 't')
                ->leftJoin('t.supportLabels','sl')
                ->andwhere('sl.user = :userId')
                ->setParameter('userId', $currentUser->getId())
                ->groupBy('sl.id');

        $ticketCountResult = $qb->getQuery()->getResult();

        $data = array();
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('sl.id,sl.name,sl.colorCode')->from("UVDeskCoreBundle:SupportLabel", 'sl')
                ->andwhere('sl.user = :userId')
                ->setParameter('userId', $currentUser->getId());

        $labels = $qb->getQuery()->getResult();

        foreach ($labels as $key => $label) {
            $labels[$key]['count'] = 0;
            foreach ($ticketCountResult as $ticketCount) {
                if(($label['id'] == $ticketCount['id']))
                    $labels[$key]['count'] = $ticketCount['ticketCount'] ?: 0;
            }
        }

        return $labels;
    }

    public function getLabels($request = null)
    {
        static $labels;
        if (null !== $labels)
            return $labels;

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('sl')->from('UVDeskCoreBundle:SupportLabel', 'sl')
            ->andwhere('sl.user = :userId')
            ->setParameter('userId', $this->getUser()->getId());

        if($request) {
            $qb->andwhere("sl.name LIKE :labelName");
            $qb->setParameter('labelName', '%'.urldecode($request->query->get('query')).'%');
        }

        return $labels = $qb->getQuery()->getArrayResult();
    }

    public function getTicketCollaborators($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select("DISTINCT c.id, c.email, CONCAT(c.firstName,' ', c.lastName) AS name, userInstance.profileImagePath, userInstance.profileImagePath as smallThumbnail")->from('UVDeskCoreBundle:Ticket', 't')
                ->leftJoin('t.collaborators', 'c')
                ->leftJoin('c.userInstance', 'userInstance')
                ->andwhere('t.id = :ticketId')
                ->andwhere('userInstance.supportRole = :roles')
                ->setParameter('ticketId', $ticketId)
                ->setParameter('roles', 4)
                ->orderBy('name','ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function getTicketTagsById($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tg')->from('UVDeskCoreBundle:Tag', 'tg')
                ->leftJoin('tg.tickets' ,'t')
                ->andwhere('t.id = :ticketId')
                ->setParameter('ticketId', $ticketId);

        return $qb->getQuery()->getArrayResult();
    }

    public function getTicketLabels($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT sl.id,sl.name,sl.colorCode')->from('UVDeskCoreBundle:Ticket', 't')
                ->leftJoin('t.supportLabels','sl')
                ->leftJoin('sl.user','slu')
                ->andWhere('slu.id = :userId')
                ->andWhere('t.id = :ticketId')
                ->setParameter('userId', $this->getUser()->getId())
                ->setParameter('ticketId', $ticketId);

        $result = $qb->getQuery()->getResult();
        
        return $result ? $result : [];
    }

    public function getManualWorkflow()
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT mw')->from('UVDeskAutomationBundle:PreparedResponses', 'mw');
        $qb->andwhere('mw.status = 1');
        
        return $qb->getQuery()->getResult();
    }

    public function getSavedReplies()
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT sr')->from('UVDeskCoreBundle:SavedReplies', 'sr');
        return $qb->getQuery()->getResult();
    }

    public function getPriorities()
    {
        static $priorities;
        if (null !== $priorities)
            return $priorities;

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tp')->from('UVDeskCoreBundle:TicketPriority', 'tp');

        return $priorities = $qb->getQuery()->getArrayResult();
    }

    public function getTicketLastThread($ticketId)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select("th")->from('UVDeskCoreBundle:Thread', 'th')
                ->leftJoin('th.ticket','t')
                ->andWhere('t.id = :ticketId')
                ->setParameter('ticketId',$ticketId)
                ->orderBy('th.id', 'DESC');

        return $qb->getQuery()->setMaxResults(1)->getSingleResult();
    }

    public function getlastReplyAgentName($ticketId)
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select("u.id,CONCAT(u.firstName,' ', u.lastName) AS name,u.firstName")->from('UVDeskCoreBundle:Thread', 'th')
                ->leftJoin('th.ticket','t')
                ->leftJoin('th.user', 'u')
                ->leftJoin('u.userInstance', 'userInstance')
                ->andwhere('userInstance.supportRole != :roles')
                ->andWhere('t.id = :ticketId')
                ->andWhere('th.threadType = :threadType')
                ->setParameter('threadType','reply')
                ->andWhere('th.createdBy = :createdBy')
                ->setParameter('createdBy','agent')
                ->setParameter('ticketId',$ticketId)
                ->setParameter('roles', 4)
                ->orderBy('th.id', 'DESC');

        $result = $qb->getQuery()->setMaxResults(1)->getResult();
        return $result ? $result[0] : null;
    }

    public function getLastReply($ticketId, $userType = null) 
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select("th, a, u.id as userId")
            ->from('UVDeskCoreBundle:Thread', 'th')
            ->leftJoin('th.ticket','t')
            ->leftJoin('th.attachments', 'a')
            ->leftJoin('th.user','u')
            ->andWhere('t.id = :ticketId')
            ->andWhere('th.threadType = :threadType')
            ->setParameter('threadType','reply')
            ->setParameter('ticketId',$ticketId)
            ->orderBy('th.id', 'DESC')
            ->getMaxResults(1);

        if (!empty($userType)) {
            $queryBuilder->andWhere('th.createdBy = :createdBy')->setParameter('createdBy', $userType);
        }
        
        $threadResponse = $queryBuilder->getQuery()->getArrayResult();
        
        if (!empty($threadResponse[0][0])) {
            $threadDetails = $threadResponse[0][0];
            $userService = $this->container->get('user.service');
            
            if ($threadDetails['createdBy'] == 'agent') {
                $threadDetails['user'] = $userService->getAgentDetailById($threadResponse[0]['userId']);
            } else {
                $threadDetails['user'] = $userService->getCustomerPartialDetailById($threadResponse[0]['userId']);
            }
            
            $threadDetails['reply'] = utf8_decode($threadDetails['message']);
            $threadDetails['formatedCreatedAt'] = $threadDetails['createdAt']->format('d-m-Y h:ia');
            $threadDetails['timestamp'] = $userService->convertToDatetimeTimezoneTimestamp($threadDetails['createdAt']);

            if (!empty($threadDetails['attachments'])) {
                $resolvedAttachmentAttributesCollection = [];
                $uvdeskFileSystemService = $this->container->get('uvdesk.core.file_system.service');

                foreach ($threadDetails['attachments'] as $attachment) {
                    $attachmentReferenceObject = $this->entityManager->getReference(Attachment::class, $attachment['id']);
                    $resolvedAttachmentAttributesCollection[] = $uvdeskFileSystemService->getFileTypeAssociations($attachmentReferenceObject);
                }

                $threadDetails['attachments'] = $resolvedAttachmentAttributesCollection;
            }
        }

        return $threadDetails ?? null;
    }

    public function getSavedReplyContent($savedReplyId, $ticketId)
    {
        $ticket = $this->entityManager->getRepository('UVDeskCoreBundle:Ticket')->find($ticketId);
        $savedReply = $this->entityManager->getRepository('UVDeskCoreBundle:SavedReplies')->findOneById($savedReplyId);
        $emailPlaceholders = $this->getSavedReplyPlaceholderValues($ticket, 'customer');

        return $this->container->get('email.service')->processEmailContent($savedReply->getMessage(), $emailPlaceholders, true);
    }

    public function getSavedReplyPlaceholderValues($ticket, $type = "customer")
    {
        $variables = array();
        $variables['ticket.id'] = $ticket->getId();
        $variables['ticket.subject'] = $ticket->getSubject();

        $variables['ticket.status'] = $ticket->getStatus()->getCode();
        $variables['ticket.priority'] = $ticket->getPriority()->getCode();
        if($ticket->getSupportGroup())
            $variables['ticket.group'] = $ticket->getSupportGroups()->getName();
        else
            $variables['ticket.group'] = '';

        $variables['ticket.team'] = ($ticket->getSupportTeam() ? $ticket->getSupportTeam()->getName() : '');

        $customer = $this->container->get('user.service')->getCustomerPartialDetailById($ticket->getCustomer()->getId());
        $variables['ticket.customerName'] = $customer['name'];
        $userService = $this->container->get('user.service');
      
        $variables['ticket.agentName'] = '';
        $variables['ticket.agentEmail'] = '';
        if ($ticket->getAgent()) {
            $agent = $this->container->get('user.service')->getAgentDetailById($ticket->getAgent()->getId());
            if($agent) {
                $variables['ticket.agentName'] = $agent['name'];
                $variables['ticket.agentEmail'] = $agent['email'];
            }
        }
        
        $router = $this->container->get('router');

        if ($type == 'customer') {
            $ticketListURL = $router->generate('helpdesk_member_ticket_collection', [
                'id' => $ticket->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            $ticketListURL = $router->generate('helpdesk_customer_ticket_collection', [
                'id' => $ticket->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $variables['ticket.link'] = sprintf("<a href='%s'>#%s</a>", $ticketListURL, $ticket->getId());

        return $variables;
    }
}


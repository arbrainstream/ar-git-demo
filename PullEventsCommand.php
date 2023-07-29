<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use App\Model\Main\EventModel;
use App\Model\Main\MaskModel;
use App\Model\Main\PerformanceModel;
use App\Model\Main\ProductModel;
use App\Model\Main\UserModel;
use App\Entity\Event\Event;
use App\Entity\Event\EventAvailability;
use App\Entity\Event\EventCategory;
use App\Entity\Event\EventI18n;
use App\Entity\Event\EventI18nList;
use App\Entity\Event\EventPerformance;
use App\Entity\Event\EventProduct;
use App\Entity\Event\EventProductWarning;
use App\Entity\Main\Category;
use App\Entity\Main\CategoryMask;
use App\Entity\Main\Controller;
use Twig\Environment;

/**
 * Pulling Events
 *
 * @author victord - November 30, 2020
 */
class PullEventsCommand extends Command {

    protected const WORKSTATIONS = ['ROM.WKS23', 'ROM.WKS42', 'ROM.WKS45', 'ROM.WKS70'];
    
    protected static $defaultName = 'app:pull-events';
//    protected static $offset = 600;    // This is the offset time required to pull new data from BOS in seconds. Eg, 10m = 600s, 1day = 86,400s
    protected static $offset = 6;    // This is the offset time required to pull new data from BOS in seconds. Eg, 10m = 600s, 1day = 86,400s
    
    protected const FORCE_NOTIFICATION = true;
    
    private $t;
    private $em;
    private $params;
    private $mailer;
    private $twig;
    private $stats = [];

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params, MailerInterface $mailer, TranslatorInterface $t, Environment $twig) {
        parent::__construct();
        $this->t = $t;
        $this->em = $em;
        $this->params = $params;
        $this->mailer = $mailer;
        $this->twig = $twig;
    }

    protected function configure() {
        $this
                ->setDescription('Downloading BOS Events.')
                ->addArgument('workstations', InputArgument::REQUIRED, 'Provide a single or multiple comma-separated workstations. Use "all" for all workstations.')
                ->addArgument('events', InputArgument::REQUIRED, 'Provide a single or multiple comma-separated events. Use "all" for all events.')
                ->setHelp(
                        'This command allows you pulling BOS Events down ' .
                        'that will be stored in a local database.'
                )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;

        $io = new SymfonyStyle($input, $output);

        $desc = $this->getDescription();

        $start = new \DateTime();

        $io->title("Updating Events - started");
        
        $workstations_ = $input->getArgument('workstations');
        $workstations = [];
        
        $events_ = $input->getArgument('events');
        $events = [];
        
        if (preg_match('/^all$/i', $workstations_)) {
            $workstations = self::WORKSTATIONS;
        } else {
            $workstations = preg_filter('/^/', 'ROM.', explode(',', trim(preg_replace('/\s+/', '', strtoupper($workstations_)))));
        }
        
        if (preg_match('/^all$/i', $events_)) {
            $events = [];
        } else {
            $events = preg_filter('/^/', 'ROM.', explode(',', trim(preg_replace('/\s+/', '', strtoupper($events_)))));
        }
        
        $now = new \DateTime();

        $controller = $this->em->getRepository(Controller::class)->findOneBy(['name' => get_class($this)]);

        if (is_null($controller)) {
            $io->text("  => Controller not found. Adding ...");
            $this->insertController($now);
        } else {
            $this->updateController($controller->getId(), $now);
        }
        
        foreach($workstations as $workstation) {
            $io->section("Workstation " . $workstation . " started.");

            $user_model = new UserModel($this->params, $this->em, $workstation);
            $user_session = $user_model->userLogin($workstation);

            $workstation_id = $user_model->workstation->getId();

            if (isset($user_session['error'])) {
                $io->error([
                    "Session Error",
                    $user_session['error']['code'] . "/" . $user_session['error']['type'] . "/" . $user_session['error']['text'] . "",
                ]);
//                        $result = Command::FAILURE;
            } else {
                // 1. Updating controlled objects
                $this->update($workstation, $events, $io);

                // 2. Updating special categories
                $this->updateCategories($workstation, $events, "account");
                $this->updateCategories($workstation, $events, "extinfo");
            }

            $mid = new \DateTime();

            $io->section("Workstation " . $workstation . " completed in " . ($mid->getTimestamp() - $start->getTimestamp()) . " seconds.");
        }

        // notify
        $this->notify();

        $result = Command::SUCCESS;

        $end = new \DateTime();

        $io->success("Updating Events - completed in " . ($end->getTimestamp() - $start->getTimestamp()) . " seconds.");

        return $result;
    }

    private function insertController(\DateTime $now) {
        $now->setTime(0, 0, 0);
        $controller = new Controller();
        $controller
                ->setName(get_class($this))
                ->setDescription($this->getDescription())
                ->setCreated($now)
                ->setCreatedby(0)
                ->setUpdated($now)
                ->setUpdatedby(0);

        $this->em->persist($controller);
        $this->em->flush();
    }

    private function updateController(int $id, \DateTime $now) {
        $controller = $this->em->getRepository(Controller::class)->find($id);

        if (!$controller) {
            throw $this->createNotFoundException('No contoller found for id ' . $id);
        }

        $controller->setDescription($this->getDescription());
        $controller->setUpdated($now);
        $this->em->flush();
    }

    private function update(string $workstation, array $events, $io) {
        $event_model = new EventModel($this->params, $this->em, $workstation);
        $performance_model = new PerformanceModel($this->params, $this->em, $workstation);
        $product_model = new ProductModel($this->params, $this->em, $workstation);
        
        $this->stats[$workstation] = [];
        
        $workstation_id = $event_model->workstation->getId();
        $workstation_object = $event_model->workstation;

        $events_ = [];
        if (empty($events)) {
            $events_ = $event_model->findAllEvents([]);
            
            // 1. Disable all events, performances, and products.
            $this->em->getRepository(Event::class)->disable($workstation_id, []);
            $this->em->getRepository(EventPerformance::class)->disable($workstation_id, []);
            $this->em->getRepository(EventProduct::class)->disable($workstation_id, []);
        } else {
            $events_ = $event_model->searchEvent(['event_aks' => $events]);
            
            // 1. Disable all events, performances, and products.
            $this->em->getRepository(Event::class)->disable($workstation_id, $events);
            $this->em->getRepository(EventPerformance::class)->disable($workstation_id, $events);
            $this->em->getRepository(EventProduct::class)->disable($workstation_id, $events);
        }
        
        // 2. Update all events that are retrieved from BOS
        if (!isset($events_['error'])) {
            printf("%-7s %-13s %-13s %s\n", "#", "event", "performances", "products");
            printf("%-7s %-13s %-13s %s\n", "-------", "-------------", "-------------", "-------------");

            $i = 1;
            foreach ($events_ as $ak => $event) {
                $object = $this->em->getRepository(Event::class)->findOneBy(['workstation' => $workstation_object, 'ak' => $event['ak']]);

                $performances_count = 0;
                $products_count = 0;
                if (!empty($object) && $object instanceof Event) {
                    $object
                            ->setWorkstation($workstation_object)
                            ->setAk($event['ak'])
                            ->setCode($event['code'])
                            ->setStatus($event['status'])
                            ->setLocationAk($event['location']['ak'])
                            ->setLocationCode($event['location']['code'])
                    ;

                    if (!empty($event['startdate'])) {
        //                $object->setStartDate((new \DateTime($event['startdate']))->format('Y-m-d H:i:s'));
                        $object->setStartDate(new \DateTime($event['startdate']));
                    }

                    if (!empty($event['enddate'])) {
        //                $object->setEndDate((new \DateTime($event['enddate']))->format('Y-m-d H:i:s'));
                        $object->setEndDate(new \DateTime($event['enddate']));
                    }

                    if (!empty($event['firstsellableperformancedate'])) {
        //                $object->setFirstSellablePerformanceDate((new \DateTime($event['firstsellableperformancedate']))->format('Y-m-d H:i:s'));
                        $object->setFirstSellablePerformanceDate(new \DateTime($event['firstsellableperformancedate']));
                    }

    //                $this->em->merge($object);
                    $this->em->persist($object);
                    $this->em->flush();

                    printf("%7d %-13s ", $i, $event['ak']);

                    // insert event relationships
                    $this->em->getRepository(EventI18n::class)->insert($object, $event['i18n']);
                    $this->em->getRepository(EventCategory::class)->insert($object, $event['category']);
                    $this->em->getRepository(EventAvailability::class)->insert($object, $event['availability']);

                    // insert performances
                    $performances = $performance_model->searchPerformance(['event_ak' => $event['ak']]);
                    $performances_count = count($performances);
                    printf("%13d ", $performances_count);
                    $this->em->getRepository(EventPerformance::class)->insert($object, $performances);

                    // insert products
                    $products = $product_model->searchProduct(['event_ak' => $event['ak']]);
                    $products_count = count($products);
                    printf("%13d \n", $products_count);
                    $this->em->getRepository(EventProduct::class)->insert($object, $products);
                } else {
                    $object = new Event();
                    $object
                            ->setWorkstation($workstation_object)
                            ->setAk($event['ak'])
                            ->setCode($event['code'])
                            ->setStatus($event['status'])
                            ->setLocationAk($event['location']['ak'])
                            ->setLocationCode($event['location']['code'])
                    ;

                    if (!empty($event['startdate'])) {
        //                $object->setStartDate((new \DateTime($event['startdate']))->format('Y-m-d H:i:s'));
                        $object->setStartDate(new \DateTime($event['startdate']));
                    }

                    if (!empty($event['enddate'])) {
        //                $object->setEndDate((new \DateTime($event['enddate']))->format('Y-m-d H:i:s'));
                        $object->setEndDate(new \DateTime($event['enddate']));
                    }

                    if (!empty($event['firstsellableperformancedate'])) {
        //                $object->setFirstSellablePerformanceDate((new \DateTime($event['firstsellableperformancedate']))->format('Y-m-d H:i:s'));
                        $object->setFirstSellablePerformanceDate(new \DateTime($event['firstsellableperformancedate']));
                    }

                    $this->em->persist($object);
                    $this->em->flush();

                    printf("%7d %-13s ", $i, $event['ak']);

                    // insert event relationships
                    $this->em->getRepository(EventI18n::class)->insert($object, $event['i18n']);
                    $this->em->getRepository(EventCategory::class)->insert($object, $event['category']);
                    $this->em->getRepository(EventAvailability::class)->insert($object, $event['availability']);

                    // insert performances
                    $performances = $performance_model->searchPerformance(['event_ak' => $event['ak']]);
                    $performances_count = count($performances);
                    printf("%13d ", $performances_count);
                    $this->em->getRepository(EventPerformance::class)->insert($object, $performances);

                    // insert products
                    $products = $product_model->searchProduct(['event_ak' => $event['ak']]);
                    $products_count = count($products);
                    printf("%13d \n", $products_count);
                    $this->em->getRepository(EventProduct::class)->insert($object, $products);
                }

                // Collecting stats for reporting
                $this->stats[$workstation][$event['ak']] = [
                    'id' => $i,
                    'event' => $event['ak'],
                    'performances' => $performances_count,
                    'products' => $products_count,
                ];

                $i++;
            }
            
            printf("\n");
        }
    }

    /**
     * This method is borrowed from PullCategoriesCommand.php
     * It will extract dmg aks attached to the event products and updates the 
     * data into category tables. Note that this method uses a different BOS API.
     */
    private function updateCategories(string $workstation, array $events, string $category_type) {
        $mask_model = new MaskModel($this->params, $this->em, $workstation);

        $workstation_id = $mask_model->workstation->getId();
        
        $dmg_aks = $this->em->getRepository(EventProductWarning::class)->getValidCategoryAksBy($workstation_id, $events, $category_type);

        foreach ($dmg_aks as $ak) {
            $category = $mask_model->readExtendedInfoByAK(['category_ak' => $ak]);
         
            if (isset($category[$ak]) && !empty($category[$ak])) {
                $object = $this->em->getRepository(Category::class)->findOneBy(['ak' => $ak]);

                if (!empty($object) && $object instanceof Category) {
                    $object
                            ->setAk($ak)
                            ->setCode($category[$ak]['code'])
                            ->setType($category[$ak]['type'])
                            ->setStatus(true)
                            ->setName($category[$ak]['name'])
                            ->setDescription($category[$ak]['description'])
                    ;
    //                $this->em->merge($object);
                    $this->em->persist($object);
                    $this->em->flush();

                    // update masks
                    $this->em->getRepository(CategoryMask::class)->insert($object, $category[$ak]['masks']);
                } else {
                    $object = new Category();
                    $object
                            ->setAk($ak)
                            ->setCode($category[$ak]['code'])
                            ->setType($category[$ak]['type'])
                            ->setStatus(true)
                            ->setName($category[$ak]['name'])
                            ->setDescription($category[$ak]['description'])
                    ;
                    $this->em->persist($object);
                    $this->em->flush();

                    // update masks
                    $this->em->getRepository(CategoryMask::class)->insert($object, $category[$ak]['masks']);
                }
            }
        }
    }

    private function notify() {
        if (self::FORCE_NOTIFICATION) {
            $url_rom = $this->params->get('url_rom');
            $url_tickets = $this->params->get('url_tickets');

            $body = "The following events performances and products have been successfully pulled from BOS.<br />";
//            $body .= "<b><span style='color: rgb(14, 162, 14)'>Things are looking good.</span></b><br />";

            $body .= $this->arrayToHtml();
            $body .= "<br /><br />";
            
            $html = $this->twig->render('mail/event.html.twig', [
                'url' => array(
                    'tickets' => $url_tickets,
                    'rom' => $url_rom,
                ),
                'text' => array(
                    'rom' => array(
                        'event' => $this->t->trans('text.rom.event'),
                        'ticket' => $this->t->trans('text.rom.ticket'),
                    ),
                ),
                'mail' => array(
                    'header' => $this->t->trans('mail.header', array(
                        '%url.tickets%' => $url_tickets,
                        '%text.rom.ticket%' => $this->t->trans('text.rom.ticket'),
                    )),
                    'greeting' => $this->t->trans('mail.greeting', array('%firstname%' => "Victor")),
                    'body' => $body,
                    'footer' => $this->t->trans('mail.footer', array(
                        '%url.tickets%' => $url_tickets,
                        '%text.rom.ticket%' => $this->t->trans('text.rom.ticket'),
                    )),
                    'powered' => $this->t->trans('mail.powered_black', array(
                        '%url.rom%' => $url_rom,
                        '%url.tickets%' => $url_tickets,
                    )),
                ),
            ]);

            if ($this->params->get('env') == "prod") {
                try {
                    $email = (new Email())
                            ->from('do-not-reply-tickets@rom.on.ca')
                            ->to('victord@rom.on.ca')
                            ->cc('coreyd@rom.on.ca', 'dishane@rom.on.ca', 'fahda@rom.on.ca')
                            ->subject('ROM Tickets - Events')
                            ->html($html);
                } catch (\Exception $e) {
                    dump($e->getMessage());
                }
            } else {
                try {
                    $email = (new Email())
                            ->from('do-not-reply-tickets@rom.on.ca')
                            ->to('victord@rom.on.ca')
                            ->subject('(TEST) ROM Tickets - Events')
                            ->html($html);
                } catch (\Exception $e) {
                    dump($e->getMessage());
                }
            }

            $this->mailer->send($email);
        }
    }

    private function arrayToHtml() {
        $workstations = array_keys($this->stats);
        $workstations_count = count($workstations);
        $events_ = [];
        foreach ($this->stats as $workstation => $stat) {
            foreach ($stat as $event_ak => $event) {
                $events_[] = $event_ak;
            }
        }
        
        $events = array_unique($events_, SORT_STRING);
        
        $html = '<table style="font-family: Verdana, Arial, Helvetica, sans-serif; font-size: 10px">';
        $html .= '<tr style="background-color: rgb(2, 136, 200); color: rgb(255, 255, 255);">';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">#</th>';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">events</th>';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;" ' . (($workstations_count > 1) ? "colspan='" . $workstations_count . "'" : "") . '>performances</th>';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;" ' . (($workstations_count > 1) ? "colspan='" . $workstations_count . "'" : "") . '>products</th>';
        $html .= '</tr>';
            
        $html .= '<tr style="background-color: rgb(2, 136, 200); color: rgb(255, 255, 255);">';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">&nbsp;</th>';
        $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">&nbsp;</th>';
        foreach ($workstations as $workstation) {
            $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">' . str_replace('ROM.', '', $workstation) . '</th>';
        }
        foreach ($workstations as $workstation) {
            $html .= '    <th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">' . str_replace('ROM.', '', $workstation) . '</th>';
        }
        $html .= '</tr>';
            
        $i = 1;
        foreach ($events as $event) {
            $html .= '<tr>';
            $html .= '    <td style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: right;">' . $i . '</td>';
            $html .= '    <td style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: left;">' . $event . '</td>';
            foreach ($workstations as $workstation) {
                $stat = array_filter($this->stats[$workstation], fn($v) => ($v['event'] == $event));
                $stat = reset($stat);
                $performances = (isset($stat) && isset($stat['performances'])) ? $stat['performances'] : 0;
                
                $html .= '<td style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: right;">' . (($performances > 0) ? $performances : "<span style='color: rgb(216, 11, 11)'>" . $performances . "</span>") . '</td>';
            }
            foreach ($workstations as $workstation) {
                $stat = array_filter($this->stats[$workstation], fn($v) => ($v['event'] == $event));
                $stat = reset($stat);
                $products = (isset($stat) && isset($stat['products'])) ? $stat['products'] : 0;
                
                $html .= '<td style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: right;">' . (($products > 0) ? $products : "<span style='color: rgb(216, 11, 11)'>" . $products . "</span>") . '</td>';
            }
            $html .= '</tr>';

            $i++;
        }
        $html .= '</table>';

        return $html;
    }
    
}

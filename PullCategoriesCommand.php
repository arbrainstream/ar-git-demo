<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use App\Model\Main\MaskModel;
use App\Model\Main\UserModel;
use App\Entity\Main\Category;
use App\Entity\Main\CategoryMask;
use App\Entity\Main\Controller;
use Twig\Environment;

/**
 * Pulling Account Categories
 *
 * @author victord - November 30, 2020
 */
class PullCategoriesCommand extends Command {

    protected const WORKSTATION_AK_MEMBER = 'ROM.WKS42';

    protected static $defaultName = 'app:pull-categories';
//    protected static $offset = 600;    // This is the offset time required to pull new data from BOS in seconds. Eg, 10m = 600s, 1day = 86,400s
    protected static $offset = 6;    // This is the offset time required to pull new data from BOS in seconds. Eg, 10m = 600s, 1day = 86,400s

    protected const FORCE_NOTIFICATION = false;

    private $t;
    private $em;
    private $params;
    private $mailer;
    private $twig;

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
                ->setDescription('Downloading BOS Account Categories.')
                ->setHelp(
                        'This command allows you pulling BOS Account Categories down ' .
                        'that will be stored in a local database.'
                )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;

        $io = new SymfonyStyle($input, $output);

        $user_model = new UserModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
        $user_session = $user_model->userLogin(self::WORKSTATION_AK_MEMBER);

        $desc = $this->getDescription();

        if (isset($user_session['error'])) {
            $io->error([
                "Session Error",
                $user_session['error']['code'] . "/" . $user_session['error']['type'] . "/" . $user_session['error']['text'] . "",
            ]);
            $result = Command::FAILURE;
        } else {
            $start = new \DateTime();

            $io->title("Updating Categories - started");

            $now = new \DateTime();

            $controller = $this->em->getRepository(Controller::class)->findOneBy(['name' => get_class($this)]);

            if (is_null($controller)) {
                $io->text("  => Controller not found. Adding ...");
                $this->insertController($now);

                $io->text("  => Data not found. Adding ...");

                // 1. Inserting controlled objects
                $this->update();
            } else {
                $last_updated = $controller->getUpdated();
                $last_updated_plus = $controller->getUpdated();
                $last_updated_plus->setTimestamp($last_updated->getTimestamp() + self::$offset);

                if ($now->getTimestamp() <= $last_updated_plus->getTimestamp()) {
                    $io->text("  => Data is up to date. No need to update.");
                } else {
                    $io->text("  => Data is out of date. Updating ...");

                    // 1. Updating controlled objects
                    $this->update();

                    // 2. Updating controller
                    $this->updateController($controller->getId(), $now);
                }
            }

            // Notify
            $categories = $this->em->getRepository(Category::class)->getCategoryWorstation(true);

            $category_aks = [];
            $workstation_aks = [];
            
            foreach ($categories as $category) {
                if (isset($category['c_ak'])) {
                    $category_aks[] = $category;
                }
                if (isset($category['w_ak'])) {
                    $workstation_aks[] = $category;
                }
            }
            
            $category_aks_count = count($category_aks);
            $workstation_aks_count = count($workstation_aks);
            
            $this->notify($categories, $category_aks_count, $workstation_aks_count);

            if ($category_aks_count != $workstation_aks_count) {
                $io->warning([
                    "ROM Tickets - Category Workstations - Action is Required",
                    "Missing category_worstation relationships may need to be established. See 'Symfony.docx > T38' for details.",
                    "Category Records             = " . $category_aks_count,
                    "Category Workstation Records = " . $workstation_aks_count,
                ]);
            }

            $categories_array = [];
            foreach ($categories as $category) {
                $categories_array[] = [
                    $category['c_id'],
                    $category['c_ak'],
                    $category['c_code'],
                    $category['status'],
                    $category['w_id'],
                    $category['w_ak'],
                    $category['w_username'],
                ];
            }

            $io->table(
                    array_keys($categories[0]),
                    $categories_array,
            );

            $result = Command::SUCCESS;

            $end = new \DateTime();

            $io->success("Updating Categories - completed in " . ($end->getTimestamp() - $start->getTimestamp()) . " seconds.");
        }

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

    private function update() {
        $mask_model = new MaskModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
        $categories = $mask_model->findAllAccountCategories([]);

        // 1. Disable and delete all categories and its masks.
        $this->em->getRepository(Category::class)->disable([]);
        
        // 2. Update all categories that are retrieved from BOS
        foreach ($categories as $ak => $category) {
            $object = $this->em->getRepository(Category::class)->findOneBy(['ak' => $ak]);

            if (!empty($object) && $object instanceof Category) {
                $object
                        ->setAk($ak)
                        ->setCode($category['code'])
                        ->setType($category['type'])
                        ->setStatus(true)
                        ->setName($category['name'])
                        ->setDescription($category['description'])
                ;
//                $this->em->merge($object);
                $this->em->persist($object);
                $this->em->flush();
                
                // update masks
                $this->em->getRepository(CategoryMask::class)->insert($object, $category['masks']);
            } else {
                $object = new Category();
                $object
                        ->setAk($ak)
                        ->setCode($category['code'])
                        ->setType($category['type'])
                        ->setStatus(true)
                        ->setName($category['name'])
                        ->setDescription($category['description'])
                ;
                $this->em->persist($object);
                $this->em->flush();

                // update masks
                $this->em->getRepository(CategoryMask::class)->insert($object, $category['masks']);
            }
        }
    }

    private function notify(array $categories, int $category_aks_count, int $workstation_aks_count) {
        if (self::FORCE_NOTIFICATION || ($category_aks_count != $workstation_aks_count)) {
            $url_rom = $this->params->get('url_rom');
            $url_tickets = $this->params->get('url_tickets');

            $body = "This is the status of <b>category_worstation</b> table. ";

            if ($category_aks_count != $workstation_aks_count) {
                $body .= "<b><span style='color: rgb(216, 11, 11)'>Action is required.</span></b> You might need to establish the missing relationships.<br />";
            } else {
                $body .= "<b><span style='color: rgb(14, 162, 14)'>Things are looking good.</span></b><br />";
            }

            $body .= $this->arrayToHtml($categories);
            $body .= "<br /><br />";

            $html = $this->twig->render('mail/category_workstation.html.twig', [
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
                            ->subject('ROM Tickets - Category Workstations' . (($category_aks_count != $workstation_aks_count) ? " - Action is Required" : ""))
                            ->html($html);
                } catch (\Exception $e) {
                    dump($e->getMessage());
                }
            } else {
                try {
                    $email = (new Email())
                            ->from('do-not-reply-tickets@rom.on.ca')
                            ->to('victord@rom.on.ca')
                            ->subject('(TEST) ROM Tickets - Category Workstations' . (($category_aks_count != $workstation_aks_count) ? " - Action is Required" : ""))
                            ->html($html);
                } catch (\Exception $e) {
                    dump($e->getMessage());
                }
            }

            $this->mailer->send($email);
        }
    }

    private function arrayToHtml($array) {
        $html = '<table style="font-family: Verdana, Arial, Helvetica, sans-serif; font-size: 11px">';
        $html .= '<tr style="background-color: rgb(2, 136, 200); color: rgb(255, 255, 255);">';
        foreach ($array[0] as $k => $v) {
            if ($k != "status") {
                $html .= '<th style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: center;">' . htmlspecialchars($k) . '</th>';
            }
        }
        $html .= '</tr>';

        foreach ($array as $k => $v) {
            $html .= '<tr>';
            foreach ($v as $k2 => $v2) {
                if ($k2 != "status") {
                    $html .= '<td style="padding: 4px 10px 4px 10px; border-bottom: #007ebe solid 1px; text-align: left;">' . htmlspecialchars($v2) . '</td>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

}

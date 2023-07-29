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
use App\Model\Main\UserModel;
use App\Model\Main\EventModel;
use App\Model\Main\PerformanceModel;
use App\Model\Main\ProductModel;
use App\Entity\Main\Controller;
use App\Entity\Event\Event;
use App\Entity\Event\EventPerformance;
use App\Entity\Event\EventProduct;
use Twig\Environment;

/**
 * A command tester.
 *
 * @author victord - November 30, 2020
 */
class TestCommand extends Command {

    protected const WORKSTATION_AK_MEMBER = 'ROM.WKS42';

    protected static $defaultName = 'app:tester';
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
        $this->setDescription('Command tester.');
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

            $io->title("Testing - started");

            $event_model = new EventModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
            $performance_model = new PerformanceModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
            $product_model = new ProductModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);

            $i = 1;

            $apis = [
                "IWsAPIEvent" => [
                    "FindAllEvents",
                    "SearchEvent",
                    "ReadEventByAK",
                ],
                "IWsAPIPerformance" => [
                    "SearchPerformance",
                    "ReadPerformanceByAK",
                ],
                "IWsAPIProduct" => [
                    "SearchProduct",
                    "ReadProductByAK",
                ],
                "IWsAPIOrder" => [
                    "CheckBasket",
                ],
            ];

            $events = [
                "ROM.EVN7",
                "ROM.EVN8",
                "ROM.EVN9",
            ];

//            try {
//                $api_function = "FindAllEvents";
//                $io->text("  => " . $i++ . "). " . $api_function);
//                
//                $bos_events = $event_model->findAllEvents([]);
//                $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
//                $filename = $this->params->get('local_bos_repo') . "/response/" . $api_call . "/" . $api_function . ".xml";                
//                $io->text("     * Filename:" . $filename);
//                $fp = fopen($filename, "w");
//                fwrite($fp, $data);
//                fclose($fp);
//
//
//                $api_function = "SearchEvent";
//                // ------------------------------------------------------------
//                $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
//                $filename = $this->params->get('local_bos_repo') . "/response/" . $api_call . "/" . $api_function . ".xml";                
//                $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
//                $io->text("         Filename: " . $filename);
//                
//                $bos_events = $event_model->searchEvent(['event_aks' => $events]);
//                $fp = fopen($filename, "w");
//                fwrite($fp, $bos_events->asXML());      // <= here is the problem. Return results are arrays not XML.
//                fclose($fp);
//
//            } catch (\Exception $e) {
//                echo $e->getMessage();
//            }



            // !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
            // Important:
            // Check setup-mac-for-bos-xml-1.sh for details.
            // !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!

            $api_function = "FindAllEvents";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            $bos_events = $event_model->findAllEvents([]);

            $api_function = "SearchEvent";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            $bos_events = $event_model->searchEvent(['event_aks' => $events]);

            $api_function = "ReadEventByAK";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            foreach ($events as $event_ak) {
                $bos_event = $event_model->readEventByAK(['event_ak' => $event_ak]);
                sleep(1);
            }

            $api_function = "SearchPerformance";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            $bos_performances = [];
            foreach ($events as $event_ak) {
                $bos_performances[] = $performance_model->searchPerformance(['event_ak' => $event_ak]);
                sleep(1);
            }

            $api_function = "ReadPerformanceByAK";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            foreach ($bos_performances as $bos_performances2) {
                foreach ($bos_performances2 as $performance_ak => $arr) {
                    $bos_performance = $performance_model->readPerformanceByAK(['performance_ak' => $performance_ak]);
                    sleep(1);
                }
            }

            $api_function = "SearchProduct";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            $bos_products = [];
            foreach ($events as $event_ak) {
                $bos_products[] = $product_model->searchProduct(['event_ak' => $event_ak]);
                sleep(1);
            }

            $api_function = "ReadProductByAK";
            $api_call = array_key_first(array_filter($apis, fn($k, $v) => (in_array($api_function, $k)), ARRAY_FILTER_USE_BOTH));
            $io->text("  => " . $i++ . "). " . $api_call . "." . $api_function);
            foreach ($bos_products as $bos_products2) {
                foreach ($bos_products2 as $product_ak => $arr) {
                    $bos_product = $product_model->readProductByAK(['product_ak' => $product_ak]);
                    sleep(1);
                }
            }




//            $model = new PerformanceModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
//            $performances = $model->searchPerformance(['event_ak' => 'ROM.EVN7']);
//            print_r($performances);
//            $model = new ProductModel($this->params, $this->em, self::WORKSTATION_AK_MEMBER);
//            $products = $model->searchProduct(['event_ak' => 'ROM.EVN7']);
//            print_r($products);



            $result = Command::SUCCESS;

            $end = new \DateTime();

            $io->success("Testing - completed in " . ($end->getTimestamp() - $start->getTimestamp()) . " seconds.");
        }

        return $result;
    }

}

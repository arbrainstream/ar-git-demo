<?php

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:cache-stat-groups
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:cache-update
/////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\DBAL\Connection;
///////////////////////////
//SOAP
use App\Model\Main\UserModel;
use Symfony\Component\DomCrawler\Crawler;
///////////////////////////
//MAIL
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class CacheUpdateCommand extends Command {

    protected static $defaultName = 'app:cache-update';
    private $em;
    private $params;
    private $user;
    private $mailer;
    private $english = 'en-US';
    private $french = 'fr-FR';

    public function __construct(EntityManagerInterface $em, MailerInterface $mailer, ParameterBagInterface $params) {
        parent::__construct();
        $this->em = $em;
        $this->params = $params;
        $this->user = new UserModel($params, $em, "ROM.WKS23"); //$this->getWorkstationAk());
        $this->mailer = $mailer;
    }

    protected function configure() {
        $this
                ->setDescription('Cache Events.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;

        $results = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        //$conn = $this->container->get('doctrine')->getConnection('default');
        //events
        $sql = "
                INSERT INTO `cache_events` (`status`,`workstation_ak`,`event_ak`,`name_en`,`name_fr`)
                SELECT 1,`workstation_ak`,`event_ak`,`name_en`,`name_fr`
                FROM tmp_events tmp 
                ON DUPLICATE KEY UPDATE `status`=1,`workstation_ak`=tmp.`workstation_ak`,`name_en`=tmp.`name_en`,`name_fr`=tmp.`name_fr`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_events` SET `status`=0 WHERE NOT (`event_ak` IN (SELECT ce.event_ak FROM `tmp_events` ce));
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //events by day
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_events_by_day` (`status`,`workstation_ak`,`date`,`event_ak`)
                SELECT 1,`workstation_ak`,`date`,`event_ak`
                FROM `tmp_events_by_day` tmp 
                ON DUPLICATE KEY UPDATE `status`=1
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events_by_day']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_events_by_day` ce
                 LEFT JOIN `tmp_events_by_day` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`date`=ce.`date` AND te.`event_ak`=ce.`event_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events_by_day']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#products
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_products` (`status`,`workstation_ak`,`product_ak`,`code`,`sheetname`,`currency`,`net`,`tax`,`gross`,`name_en`,`name_fr`)
                SELECT 1,`workstation_ak`,`product_ak`,`code`,`sheetname`,`currency`,`net`,`tax`,`gross`,`name_en`,`name_fr`
                FROM tmp_products tmp 
                ON DUPLICATE KEY UPDATE `status`=1,`code`=tmp.`code`,`sheetname`=tmp.`sheetname`,`currency`=tmp.`currency`,`net`=tmp.`net`,`tax`=tmp.`tax`,`gross`=tmp.`gross`,`name_en`=tmp.`name_en`,`name_fr`=tmp.`name_fr`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_products']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_products` SET `status`=0 WHERE NOT (`product_ak` IN (SELECT ce.product_ak FROM `tmp_products` ce));
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_products']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#statgroups
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_stat_groups` (`status`,`workstation_ak`,`code`,`event_ak`)
                SELECT 1,`workstation_ak`,`code`,`event_ak`
                FROM `tmp_statgroups` tmp 
                ON DUPLICATE KEY UPDATE `status`=1
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_stat_groups` ce
                 LEFT JOIN `tmp_statgroups` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`code`=ce.`code` AND te.`event_ak`=ce.`event_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#statgroups products
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_stat_groups_products` (`status`,`workstation_ak`,`code`,`product_ak`)
                SELECT 1,`workstation_ak`,`code`,`product_ak`
                FROM `tmp_statgroups_products` tmp 
                ON DUPLICATE KEY UPDATE `status`=1
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups_products']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_stat_groups_products` ce
                 LEFT JOIN `tmp_statgroups_products` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`code`=ce.`code` AND te.`product_ak`=ce.`product_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups_products']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        ///////////////////////////////////////////////

        foreach ($results as $k => $v) {
            $sql = "SELECT COUNT(1) total_count,COUNT(status>0) total_active_count FROM `$k`;";
            $query = $this->em->getConnection()->prepare($sql);
            $query->execute();
            $res = $query->fetchAll();
            try {
                $results[$k]['total_count'] = $res[0]['total_count'];
            } catch (\Exception $e) {
                $results[$k]['total_count'] = 0;
            }
            try {
                $results[$k]['total_active_count'] = $res[0]['total_active_count'];
            } catch (\Exception $e) {
                $results[$k]['total_active_count'] = 0;
            }
        }

        //var_dump($sg_to_insert);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'sg_to_insert' => $results
        ));

        $this->email_report($results);

        return Command::SUCCESS;
    }

    ///////////////////////////////////////////////////////
    //email report for caching
    ///////////////////////////////////////////////////////
    private function email_report($results) {
        $html = "";
        $html .= "<table><thead><tr><td>table name</td><td>insert/update</td><td>deactivated</td><td>total_count</td><td>total_active_count</td></tr></thead>";
        $html .= "<tbody>";
        foreach ($results as $k => $r) {
            $html .= "<tr><td>$k</td><td>$r[insert_update]</td><td>$r[deactivated]</td><td>$r[total_count]</td><td>$r[total_active_count]</td></tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";

        try {
            $email = (new Email())
                    ->from('do-not-reply-tickets@rom.on.ca')
//                    ->to('victord@rom.on.ca')
                    ->to('dishane@rom.on.ca')
                    ->cc('victord@rom.on.ca', 'coreyd@rom.on.ca', 'fahda@rom.on.ca')
                    ->subject('Cache Report (ROM Tickets)')
                    ->html($html);
            $this->mailer->send($email);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

}

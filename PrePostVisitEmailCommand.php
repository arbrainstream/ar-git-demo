<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Connection;

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:pre-post-visit-email prepare
//  php bin/console app:pre-post-visit-email execute
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:pre-post-visit-email prepare
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:pre-post-visit-email execute
/////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////

class PrePostVisitEmailCommand extends Command {

    protected static $defaultName = 'app:pre-post-visit-email';

    protected const ACTION_PREPARE = "prepare";
    protected const ACTION_EXECUTE = "execute";

    private $t;
    private $em;
    private $params;
    private $mailer;
    private $conn;
    private $conn_bos;
    private $processing_date;

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params, ContainerInterface $container, MailerInterface $mailer, TranslatorInterface $t) {
        parent::__construct();
        $this->t = $t;
        $this->em = $em;
        $this->params = $params;
        $this->mailer = $mailer;
        $this->conn = $container->get('doctrine')->getManager()->getConnection();
        $this->conn_bos = $container->get('doctrine')->getManager('bos')->getConnection('bos');
        $this->processing_date = date('Y-m-d', strtotime("Now"));
    }

    protected function configure() {
        $this
                ->setDescription('Pre-, Post-visits emails.')
                // actions: (1) prepare (2) execute
                ->addArgument('action', InputArgument::REQUIRED, 'Action to perform')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $start = new \DateTime();

        $result = Command::FAILURE;

        $io = new SymfonyStyle($input, $output);

        $io->title("Pre-Visits & Post-Visits - started");

        $action = $input->getArgument('action');

        if ($action == self::ACTION_PREPARE) {
            $io->info("Preparing emails");

            $email_from_non_mem = 'Royal Ontario Museum <info@rom.on.ca>';
            $email_from_mem = 'ROM Membership <membership@rom.on.ca>';

            $url_pre_visit = 'https://projects.rom.on.ca/ecommunications/previsit.html';
            $subject = 'What You Need to Know Before You Visit';
            $date_filter_field = "FORMAT(cnf_performance.PerfDateTime, 'yyyy-MM-dd')";
            $this->populate_based_on_visit_date(-1, [0], '', $url_pre_visit, $subject, $email_from_non_mem, $date_filter_field);
            $this->populate_based_on_visit_date(-1, [0], 'NOT', $url_pre_visit, $subject, $email_from_non_mem, $date_filter_field);
            $this->populate_based_on_visit_date(-1, [1], '', $url_pre_visit, $subject, $email_from_mem, $date_filter_field);
            $this->populate_based_on_visit_date(-1, [1], 'NOT', $url_pre_visit, $subject, $email_from_mem, $date_filter_field);

            // Issues with the BOS Query. Thus, as per Fahd, disabling Post-Visits notifications for now. - victord
            $url_post_visit_public = 'https://projects.rom.on.ca/ecommunications/postvisit.html';
            $subject = 'Thank you for visiting the ROM!';
            $date_filter_field = "Usage.UsageDate";
            $this->populate_based_on_visit_date(1, [0], '', $url_post_visit_public, $subject, $email_from_non_mem, $date_filter_field);
            $this->populate_based_on_visit_date(1, [0], 'NOT', $url_post_visit_public, $subject, $email_from_non_mem, $date_filter_field);

            $url_post_visit_members = 'https://projects.rom.on.ca/ecommunications/postvisit_members.html';
            $subject = 'Thank you for visiting the ROM!';
            $date_filter_field = "Usage.UsageDate";
            $this->populate_based_on_visit_date(1, [1], '', $url_post_visit_members, $subject, $email_from_mem, $date_filter_field);
            $this->populate_based_on_visit_date(1, [1], 'NOT', $url_post_visit_members, $subject, $email_from_mem, $date_filter_field);
            //Use email web tickets were sent to
            $web_count = $this->update_emails_if_web();
            $exclude_count = $this->exclude();
            $io->text("");
            $io->text("  => Web count of email overrides : " . $web_count);
            $io->text("  => Exclusions                   : " . $exclude_count);

            $result = Command::SUCCESS;

            $end = new \DateTime();

            $io->success("Updating Categories - completed in " . ($end->getTimestamp() - $start->getTimestamp()) . " seconds.");
        } elseif ($action == self::ACTION_EXECUTE) {
            $io->info("Sending emails");

            //Use email web tickets were sent to
            $web_count = $this->update_emails_if_web();
            $exclude_count = $this->exclude();
            $io->text("");
            $io->text("  => Web count of email overrides : " . $web_count);
            $io->text("  => Exclusions                   : " . $exclude_count);

            // send email(s)
            $email_results = $this->send_pre_and_post_visit_emails();

            // send report
            $this->send_report($email_results);

            $result = Command::SUCCESS;

            $end = new \DateTime();

            $io->success("Updating Categories - completed in " . ($end->getTimestamp() - $start->getTimestamp()) . " seconds.");
        } else {
            $io->error([
                "Parameter Error",
                "Command not understood",
            ]);

            $result = Command::FAILURE;
        }

        return $result;
    }

    protected function getCategoryAks($usernames) {
        $sql = "select distinct c.ak category_ak " .
                "from category_workstation cw inner join " .
                "    workstation w on (w.id = cw.workstation_id) inner join " .
                "    category c on (c.id = cw.category_id) " .
                "where username in (:usernames) ";

        $qry = $this->conn->executeQuery($sql, ['usernames' => $usernames], ['usernames' => Connection::PARAM_STR_ARRAY]);
        $rows = $qry->fetchAll();

        $category_aks = [];
        if (count($rows) > 0) {
            foreach ($rows as $key => $row) {
                $category_aks[] = $row['category_ak'];
            }
        }

        return $category_aks;
    }

    private function send_report($results) {
        $html = '<table>';
        $html .= '    <tr><td>emails sent now</td><td>' . $results['successful'] . '</td></tr>';
        $html .= '    <tr><td>emails failed now</td><td>' . $results['failed'] . '</td></tr>';
        $html .= '</table>';
        $html .= '<br />';

        //error message
        $sql = "select e.email_error, count(e.email_error) cnt " .
                "from pre_post_visit_email e " .
                "where e.email is not null " .
                "    and e.email != '' " .
                "    and e.processing_date = :processing_date " .
                "    and (not(e.email_error is null)) and e.email_error != '' " .
                "group by e.email_error ";

        $qry = $this->conn->executeQuery($sql, ['processing_date' => $this->processing_date], []);
        $rows = $qry->fetchAll();

        if (count($rows) > 0) {
            $html .= '<table>';
            $html .= '<tr><td>#</td><td>email errors today</td></tr>';
            foreach ($rows as $key => $row) {
                $html .= '<tr><td>' . $row['cnt'] . '</td><td>' . $row['email_error'] . '</td></tr>';
            }
            $html .= '</table><br />';
        }

        //day email content sent
        $sql = "select e.email_url, sum(if(e.email_sent > 0, 1, 0)) sent, sum(if(e.email_sent > 0, 0, 1)) failed " .
                "from pre_post_visit_email e " .
                "where e.email is not null " .
                "    and e.email != '' " .
                "    and e.processing_date = :processing_date " .
                "group by e.email_url ";

        $qry = $this->conn->executeQuery($sql, ['processing_date' => $this->processing_date], []);
        $rows = $qry->fetchAll();

        $html .= '<table>';
        $html .= '<tr><td>content url</td><td>sent today</td><td>failed today</td></tr>';
        foreach ($rows as $key => $row) {
            $html .= '<tr><td>' . $row['email_url'] . '</td><td>' . $row['sent'] . '</td><td>' . $row['failed'] . '</td></tr>';
        }
        $html .= '</table><br />';

        $email = (new Email())
                ->from('Royal Ontario Museum <info@rom.on.ca>')
//                ->to('dishan.edirmanasinghe@rom.on.ca')
//                ->to('victor.diaz@rom.on.ca')
                ->to('suzannep@rom.on.ca', 'romits@rom.on.ca', 'dishan.edirmanasinghe@rom.on.ca', 'fahd.ansari@rom.on.ca', 'victor.diaz@rom.on.ca', 'coreyd@rom.on.ca')
                ->subject('ROM Tickets - Pre/Post Visit Email Report')
                ->html($html);
        $this->mailer->send($email);
    }

    private function send_pre_and_post_visit_emails() {
        $sql = "select group_concat(e.id order by e.id asc) ids_csv, e.email_from, e.email, e.email_subject, e.email_url, e.processing_date " .
                "from pre_post_visit_email e " .
                "where e.email is not null " .
                "    and e.email != '' " .
                "    and e.processing_date = :processing_date " .
                "    and e.email_sent = 0 " .
                "group by e.email_from, e.email, e.email_subject, e.email_url, e.processing_date; ";
        //GROUP BY to prevent duplicate emails being sent

        $qry = $this->conn->executeQuery($sql, ['processing_date' => $this->processing_date], []);
        $rows = $qry->fetchAll();

        $successful = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $html = \file_get_contents($row['email_url']);

                $email = (new Email())
                        ->from($row['email_from'])
                        ->to($row['email'])
                        //->bcc('suzannep@rom.on.ca')
                        //->bcc('suzannep@rom.on.ca', 'dishane@rom.on.ca')
                        ->subject($row['email_subject'])
                        ->html($html);
                $this->mailer->send($email);

                //success: email sent
                $sql = "update pre_post_visit_email e set e.email_sent = 1, email_error = '', ids_sent_with_csv=:ids_sent_with_csv  where e.id IN (:ids);";
                $qry = $this->conn->executeQuery($sql, ['ids_sent_with_csv' => $row['ids_csv'], 'ids' => explode(',', $row['ids_csv'])], ['ids' => Connection::PARAM_STR_ARRAY]);
                $successful = $successful + 1; //($qry->rowCount());
            } catch (\Exception $e) {
                //failed: email
                $err_msg = $e->getMessage();
                $sql = "update pre_post_visit_email e set e.email_sent = 0, email_error = :email_error, ids_sent_with_csv=:ids_sent_with_csv where e.id IN (:ids);";
                $qry = $this->conn->executeQuery($sql, ['ids_sent_with_csv' => $row['ids_csv'], 'ids' => explode(',', $row['ids_csv']), 'email_error' => $err_msg], ['ids' => Connection::PARAM_STR_ARRAY]);
                $failed = $failed + ($qry->rowCount());
            }

            //(!) WARNING: prevent from flooding. (mail server limit of 30 messages sent per minute)
            \sleep(3);
        }

        return ['successful' => $successful, 'failed' => $failed];
    }

    private function update_emails_if_web() {
        $sql = "update pre_post_visit_email e " .
                "    inner join cart c on (c.sale_ak = e.sale_ak) " .
                "set e.email = c.email " .
                "where c.id is not null " .
                "    and c.status = 'close' " .
                "    and c.email is not null " .
                "    and e.processing_date = :processing_date " .
                "    and e.email_sent = 0 ";

        $qry = $this->conn->executeQuery($sql, ['processing_date' => $this->processing_date], []);

        return $qry->rowCount();
    }

    /*
     * Excluding cart transactions from sending emails to these records.
     * This is a result of WEB-3979 and confirmation from Fahd that transaction
     * of type events will not be sent pre- and post-visits emails.
     * 
     * This is where i'll put further exclusion criteria.
     * 
     * victord - Nov 26, 2022
     */
    private function exclude() {
        $sql = "update pre_post_visit_email e " .
                "    inner join cart c on (c.sale_ak = e.sale_ak) " .
                "set e.email = '' " .
                "where e.processing_date = :processing_date " .
                "    and c.transaction_type in ('event') ";

        $qry = $this->conn->executeQuery($sql, ['processing_date' => $this->processing_date], []);

        return $qry->rowCount();
    }

    /**
     * Config Vars
     * for $days_before_or_after -ve is before today, 0 is today, +ve is after today
     * $is_member = [1]; || $is_member = [0]; || $is_member = [0,1];
     * $in_ontario = ''; //YES
     * $in_ontario = 'NOT'; //NO
     * 
     * @param type $days_before_or_after
     * @param type $is_member
     * @param type $in_ontario
     * @param type $url
     * @param type $subject
     * @param type $email_from
     */
    private function populate_based_on_visit_date($days_before_or_after, $is_member, $in_ontario, $url, $subject, $email_from, $date_filter_field = "FORMAT(cnf_performance.PerfDateTime, 'yyyy-MM-dd')") {
        $day_todo = date('Y-m-d', strtotime("today " . -$days_before_or_after . " day"));

        if (max($is_member) >= 1) {
            $category_aks = $this->getCategoryAks(['webmember', 'webpatron']);
        } elseif (max($is_member) < 1) {
            $category_aks = $this->getCategoryAks(['webb2c', 'webamex']);
        }

        //select from bos sql server db
        //https://shop.omniticket.net/documentation/BOS/default.html
        $sql = "
            select DISTINCT 
                data_sale.saleak,
                DATA_Reservation.ReservationAK, 
                FORMAT(cnf_performance.PerfDateTime, 'yyyy-MM-dd') visit_date,
                Usage.UsageDate usage_date,
                DATA_Account.AccountAk, 
                DATA_Account.EmailAddress1 email,
                DATA_Account.ZipCode postal_code, 
                DATA_Account.Membership is_member, 
                CNF_DmgCategory.dmgcategoryid dmg_category_id,
                CNF_DmgCategory.description dmg_category_description,
                cnf_operatingArea.OpAreaName op_area_name
                /*CNF_MatrixCell.MatrixcellAK*/
            from
                data_sale
                INNER JOIN data_saleitem ON data_saleitem.saleid = data_sale.saleid
                INNER JOIN DATA_SaleItem2Performance ON data_saleitem2Performance.saleitemid = data_saleitem.saleitemid
                INNER JOIN cnf_performance ON cnf_performance.Performanceid = data_saleitem2performance.performanceID
                LEfT  JOIN DATA_Reservation ON data_reservation.saleid = data_sale.saleid
                LEFT  JOIN DATA_Account ON data_account.accountid = data_reservation.Accountid
                LEFT  JOIN CNF_DmgCategory ON CNF_DmgCategory.dmgcategoryid = data_account.dmgcategoryid 
                /*INNER JOIN CNF_MatrixCell ON cnf_matrixcell.matrixcellid = data_saleitem.matrixcellid*/
                INNER JOIN cnf_workstation ON cnf_workstation.workstationid = data_sale.workstationid
                INNER JOIN cnf_operatingArea ON cnf_operatingArea.operatingAreaid = cnf_workstation.operatingAreaid
                
LEFT JOIN (
SELECT DATA_SaleItem2Ticket.SaleItemId,MAX(FORMAT(DATA_TicketUsage.UsageDate, 'yyyy-MM-dd')) UsageDate
FROM DATA_SaleItem2Ticket 
 INNER JOIN DATA_TicketUsage ON DATA_TicketUsage.TicketId=DATA_SaleItem2Ticket.TicketId
WHERE FORMAT(DATA_TicketUsage.UsageDate, 'yyyy-MM-dd')=:day_todo
GROUP BY DATA_SaleItem2Ticket.SaleItemId
) as Usage ON Usage.SaleItemId=DATA_SaleItem2Performance.SaleItemId

            where
                $date_filter_field like :day_todo
                AND CNF_DmgCategory.DmgCategoryAK IN (:category_aks)
                AND DATA_Account.EmailAddress1 IS NOT NULL 
                AND LEN(DATA_Account.EmailAddress1) > 0
                AND UPPER(TRIM(REPLACE(DATA_Account.ZipCode, ' ', ''))) $in_ontario LIKE '[M|L]%'
                AND data_sale.StatusCode = 1
                AND data_sale.Approved = 1
                AND data_sale.Validated = 1
                AND data_sale.Completed = 1
                AND data_sale.Operation = 1
        ";
//                /*AND DATA_Account.Membership IN (:is_member)*/

        $stmt = $this->conn_bos->executeQuery($sql, [
            'day_todo' => $day_todo,
//            'is_member' => $is_member,
            'category_aks' => $category_aks
                ], [
//            'is_member' => Connection::PARAM_STR_ARRAY,
            'category_aks' => Connection::PARAM_STR_ARRAY
        ]);

        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $sql = "insert into pre_post_visit_email (sale_ak, reservation_ak, processing_date, visit_date, usage_date, account_ak, postal_code, is_member, dmg_category_id, dmg_category_description, op_area_name, email, email_from, email_url, email_subject, email_sent, email_error) " .
                    "values (:sale_ak, :reservation_ak, :processing_date, :visit_date, :usage_date, :account_ak, :postal_code, :is_member, :dmg_category_id, :dmg_category_description, :op_area_name, :email, :email_from, :email_url, :email_subject, :email_sent, :email_error) " .
                    "on duplicate key update id = id; ";

            try {
                $this->conn->executeQuery($sql, [
                    'sale_ak' => $row['saleak'],
                    'reservation_ak' => $row['ReservationAK'],
                    'processing_date' => $this->processing_date,
                    'visit_date' => $row['visit_date'],
                    'usage_date' => $row['usage_date'],
                    'account_ak' => $row['AccountAk'],
                    'postal_code' => $row['postal_code'],
                    'is_member' => $row['is_member'],
                    'dmg_category_id' => $row['dmg_category_id'],
                    'dmg_category_description' => $row['dmg_category_description'],
                    'op_area_name' => $row['op_area_name'],
                    'email' => $row['email'],
                    'email_from' => $email_from,
                    'email_url' => $url,
                    'email_subject' => $subject,
                    'email_sent' => 0,
                    'email_error' => NULL,
                        ], []);
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
    }

}

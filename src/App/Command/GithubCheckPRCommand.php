<?php
namespace Console\App\Command;

use DateInterval;
use DateTime;
use Github\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
 
class GithubCheckPRCommand extends Command
{
    /**
     * @var Client;
     */
    protected $client;

    protected function configure()
    {
        $this->setName('github:check:pr')
            ->setDescription('Check Github PR')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN']
            )
            ->addOption(
                'username',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_USERNAME']
            );
        
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->client = new Client();
        $ghToken = $input->getOption('ghtoken');
        if (!empty($ghToken)) {
            $this->client->authenticate($ghToken, null, Client::AUTH_URL_TOKEN);
        }

        $table = new Table($output);
        $table->setStyle('box');

        // Check Merged PR (Milestone, Issue & Milestone)
        $hasRows = $this->checkMergedPR($input, $output, $table, false);
        // Check PR waiting for merge
        $hasRows = $this->checkPRWaitingForMerge($input, $output, $table, $hasRows);
        // Check PR waiting for QA
        $hasRows = $this->checkPRWaitingForQA($input, $output, $table, $hasRows);
        // Check PR waiting for PM
        $hasRows = $this->checkPRWaitingForPM($input, $output, $table, $hasRows);
        // Check PR waiting for UX
        $hasRows = $this->checkPRWaitingForUX($input, $output, $table, $hasRows);
        // Check PR waiting for Wording
        $hasRows = $this->checkPRWaitingForWording($input, $output, $table, $hasRows);

        $table->render();
    }

    private function checkMergedPR(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $date = new DateTime();
        $date->sub(new DateInterval('P1D'));

        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:pr is:merged merged:>'.$date->format('Y-m-d'));
        return $this->checkPR('Merged PR', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPRWaitingForMerge(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:open is:pr label:"QA ✔️"');
        return $this->checkPR('PR Waiting for Merge', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPRWaitingForQA(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:open is:pr label:"waiting for QA"');
        return $this->checkPR('PR Waiting for QA', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPRWaitingForPM(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:open is:pr label:"waiting for PM"');
        return $this->checkPR('PR Waiting for PM', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPRWaitingForUX(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:open is:pr label:"waiting for UX"');
        return $this->checkPR('PR Waiting for UX', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPRWaitingForWording(InputInterface $input, OutputInterface $output, Table $table, bool $hasRows)
    {
        $mergedPullRequests = $this->client->api('search')->issues('org:PrestaShop is:open is:pr label:"waiting for Wording"');
        return $this->checkPR('PR Waiting for Wording', $mergedPullRequests, $output, $table, $hasRows);
    }

    private function checkPR(string $title, array $returnSearch, OutputInterface $output, Table $table, bool $hasRows)
    {
        $rows = [];
        foreach($returnSearch['items'] as $pullRequest) {
            $linkedIssue = $this->getIssue($output, $pullRequest);
            $repoName = str_replace('https://api.github.com/repos/PrestaShop/', '', $pullRequest['repository_url']);
            
            $rows[] = [
                '<href=https://github.com/PrestaShop/'.$repoName.'>'.$repoName.'</>',
                '<href='.$pullRequest['html_url'].'>#'.$pullRequest['number'].'</>',
                $pullRequest['created_at'],
                $pullRequest['title'],
                '<href='.$pullRequest['user']['html_url'].'>'.$pullRequest['user']['login'].'</>',
                !empty($pullRequest['milestone']) ? '    <info>✓</info>' : '    <error>✗ </error>',
                !is_null($linkedIssue) && $repoName == 'PrestaShop'
                    ? (!empty($linkedIssue['milestone']) ? '<info>✓ </info>' : '<error>✗ </error>') .' <href='.$linkedIssue['html_url'].'>#'.$linkedIssue['number'].'</>'
                    : '',
            ];
        }
        if (empty($rows)) {
            return $hasRows;
        }
        if ($hasRows) {
            $table->addRows([new TableSeparator()]);
        }
        $table->addRows([
            [new TableCell('<fg=black;bg=white;options=bold> ' . $title . ' </>', ['colspan' => 7])],
            new TableSeparator(),
            ['<info>Project</info>', '<info>#</info>', '<info>Created At</info>','<info>Title</info>', '<info>Author</info>', '<info>Milestone</info>', '<info>Issue</info>'],
            new TableSeparator(),
        ]);
        $table->addRows($rows);
        return true;
    }

    private function getIssue(OutputInterface $output, array $pullRequest)
    {
        // Linked Issue
        preg_match('#Fixes\s\#([0-9]{1,5})#', $pullRequest['body'], $matches);
        $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        if (empty($issueId)) {
            preg_match('#Fixes\sissue\s\#([0-9]{1,5})#', $pullRequest['body'], $matches);
            $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        }
        if (empty($issueId)) {
            preg_match('#Fixes\shttps:\/\/github.com\/PrestaShop\/PrestaShop\/issues\/([0-9]{1,5})#', $pullRequest['body'], $matches);
            $issueId = !empty($matches) && !empty($matches[1]) ? $matches[1] : null;
        }
        $issue = is_null($issueId) ? null : $this->client->api('issue')->show('PrestaShop', 'PrestaShop', $issueId);

        // API Alert
        if (isset($pullRequest['_links'])) {
            $output->writeln('PR #'.$pullRequest['number'].' has _links in its API');
        }

        return $issue;
    }
}
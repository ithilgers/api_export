<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Command;

use Ithilgers\ApiExport\Security\ClientToken;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Generates a new token for an API client and prints it exactly once.
 *
 *   vendor/bin/typo3 api-export:client:token <uid>
 *
 * The token is saved via the DataHandler, like in the backend form: only its
 * hash and hint are stored, the change is logged, and the previous token
 * stops working immediately. Only the token goes to stdout, notes (e.g. that
 * the client is deactivated) go to stderr.
 */
final class ClientTokenCommand extends Command
{
    private const TABLE = 'tx_apiexport_domain_model_client';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Generates a new token for the API client with the given uid, saves it like the backend form does'
                . ' and prints it once. The previous token of the client stops working immediately.'
            )
            ->addArgument('uid', InputArgument::REQUIRED, 'uid of the API client');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $uidArgument = (string)$input->getArgument('uid');
        $client = MathUtility::canBeInterpretedAsInteger($uidArgument) && (int)$uidArgument > 0
            ? $this->findClient((int)$uidArgument)
            : null;
        if ($client === null) {
            $errorOutput->writeln(sprintf('<error>There is no API client with uid "%s".</error>', $uidArgument));
            return Command::FAILURE;
        }
        $uid = (int)$client['uid'];

        Bootstrap::initializeBackendAuthentication();
        $token = ClientToken::generate();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => ['token' => $token]]], []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== [] || ($this->findClient($uid)['token_hash'] ?? null) !== ClientToken::hash($token)) {
            foreach ($dataHandler->errorLog as $error) {
                $errorOutput->writeln('<error>' . $error . '</error>');
            }
            $errorOutput->writeln(sprintf('<error>The token of API client %d could not be saved.</error>', $uid));
            return Command::FAILURE;
        }

        $output->writeln($token, OutputInterface::OUTPUT_RAW);

        $now = (int)($GLOBALS['EXEC_TIME'] ?? time());
        if ((bool)$client['hidden']) {
            $errorOutput->writeln(sprintf('Note: API client %d is deactivated; the token only works once it is activated.', $uid));
        }
        if ((int)$client['starttime'] > $now || ((int)$client['endtime'] !== 0 && (int)$client['endtime'] <= $now)) {
            $errorOutput->writeln(sprintf('Note: API client %d is outside its access validity; the token only works within it.', $uid));
        }
        return Command::SUCCESS;
    }

    /**
     * Existing, not deleted client (also if deactivated or expired).
     *
     * @return array<string, mixed>|null
     */
    private function findClient(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $queryBuilder
            ->select('uid', 'hidden', 'starttime', 'endtime', 'token_hash')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }
}

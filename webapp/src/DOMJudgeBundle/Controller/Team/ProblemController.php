<?php declare(strict_types=1);

namespace DOMJudgeBundle\Controller\Team;

use Doctrine\ORM\EntityManagerInterface;
use DOMJudgeBundle\Controller\BaseController;
use DOMJudgeBundle\Entity\ContestProblem;
use DOMJudgeBundle\Entity\TestcaseWithContent;
use DOMJudgeBundle\Service\DOMJudgeService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class ProblemController
 *
 * @Route("/team")
 * @Security("is_granted('ROLE_TEAM')")
 * @Security("user.getTeam() !== null", message="You do not have a team associated with your account.")
 *
 * @package DOMJudgeBundle\Controller\Team
 */
class ProblemController extends BaseController
{
    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * ProblemController constructor.
     * @param DOMJudgeService        $DOMJudgeService
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(DOMJudgeService $DOMJudgeService, EntityManagerInterface $entityManager)
    {
        $this->DOMJudgeService = $DOMJudgeService;
        $this->entityManager   = $entityManager;
    }

    /**
     * @Route("/problems", name="team_problems")
     * @return Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Exception
     */
    public function problemsAction()
    {
        $user               = $this->DOMJudgeService->getUser();
        $contest            = $this->DOMJudgeService->getCurrentContest($user->getTeamid());
        $showLimits         = (bool)$this->DOMJudgeService->dbconfig_get('show_limits_on_team_page');
        $defaultMemoryLimit = (int)$this->DOMJudgeService->dbconfig_get('memory_limit', 0);
        $timeFactorDiffers  = false;
        if ($showLimits) {
            $timeFactorDiffers = $this->entityManager->createQueryBuilder()
                    ->from('DOMJudgeBundle:Language', 'l')
                    ->select('COUNT(l)')
                    ->andWhere('l.allowSubmit = true')
                    ->andWhere('l.timeFactor <> 1')
                    ->getQuery()
                    ->getSingleScalarResult() > 0;
        }

        $problems = [];
        if ($contest && $contest->getFreezeData()->started()) {
            $problems = $this->entityManager->createQueryBuilder()
                ->from('DOMJudgeBundle:ContestProblem', 'cp')
                ->join('cp.problem', 'p')
                ->leftJoin('p.testcases', 'tc')
                ->select('p', 'cp', 'SUM(tc.sample) AS numsamples')
                ->andWhere('cp.contest = :contest')
                ->andWhere('cp.allowSubmit = 1')
                ->setParameter(':contest', $contest)
                ->addOrderBy('cp.shortname')
                ->groupBy('cp.probid')
                ->getQuery()
                ->getResult();
        }

        return $this->render('@DOMJudge/team/problems.html.twig', [
            'problems' => $problems,
            'showLimits' => $showLimits,
            'defaultMemoryLimit' => $defaultMemoryLimit,
            'timeFactorDiffers' => $timeFactorDiffers,
        ]);
    }


    /**
     * @Route("/problems/{probId}/text", name="team_problem_text", requirements={"probId": "\d+"})
     * @param int $probId
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function problemTextAction(int $probId)
    {
        $user    = $this->DOMJudgeService->getUser();
        $contest = $this->DOMJudgeService->getCurrentContest($user->getTeamid());
        if (!$contest || !$contest->getFreezeData()->started()) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }
        /** @var ContestProblem $contestProblem */
        $contestProblem = $this->entityManager->getRepository(ContestProblem::class)->find([
                                                                                               'probid' => $probId,
                                                                                               'cid' => $contest->getCid(),
                                                                                           ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }

        $problem = $contestProblem->getProblem();

        switch ($problem->getProblemtextType()) {
            case 'pdf':
                $mimetype = 'application/pdf';
                break;
            case 'html':
                $mimetype = 'text/html';
                break;
            case 'txt':
                $mimetype = 'text/plain';
                break;
            default:
                $this->addFlash('danger', sprintf('Problem p%d text has unknown type', $probId));
                return $this->redirectToRoute('team_problems');
        }

        $filename    = sprintf('prob-%s.%s', $problem->getName(), $problem->getProblemtextType());
        $problemText = stream_get_contents($problem->getProblemtext());

        $response = new StreamedResponse();
        $response->setCallback(function () use ($problemText) {
            echo $problemText;
        });
        $response->headers->set('Content-Type', sprintf('%s; name="%s', $mimetype, $filename));
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s"', $filename));
        $response->headers->set('Content-Length', strlen($problemText));

        return $response;
    }

    /**
     * @Route(
     *     "/{probId}/sample/{index}/{type}",
     *     name="team_problem_sample_testcase",
     *     requirements={"probId": "\d+", "index": "\d+", "type": "input|output"}
     *     )
     * @param int    $probId
     * @param int    $index
     * @param string $type
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function sampleTestcaseAction(int $probId, int $index, string $type)
    {
        $user    = $this->DOMJudgeService->getUser();
        $contest = $this->DOMJudgeService->getCurrentContest($user->getTeamid());
        if (!$contest || !$contest->getFreezeData()->started()) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }
        /** @var ContestProblem $contestProblem */
        $contestProblem = $this->entityManager->getRepository(ContestProblem::class)->find([
                                                                                               'probid' => $probId,
                                                                                               'cid' => $contest->getCid(),
                                                                                           ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }

        /** @var TestcaseWithContent $testcase */
        $testcase = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:TestcaseWithContent', 'tc')
            ->join('tc.problem', 'p')
            ->join('p.contest_problems', 'cp', Join::WITH, 'cp.contest = :contest')
            ->select('tc')
            ->andWhere('tc.probid = :problem')
            ->andWhere('tc.sample = 1')
            ->andWhere('cp.allowSubmit = 1')
            ->setParameter(':problem', $probId)
            ->setParameter(':contest', $contest)
            ->orderBy('tc.testcaseid')
            ->setMaxResults(1)
            ->setFirstResult($index - 1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$testcase) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }

        $extension = substr($type, 0, -3);
        $mimetype  = 'text/plain';

        $filename = sprintf("sample-%s.%s.%s", $contestProblem->getShortname(), $index, $extension);
        $content  = null;

        switch ($type) {
            case 'input':
                $content = stream_get_contents($testcase->getInput());
                break;
            case 'output':
                $content = stream_get_contents($testcase->getOutput());
                break;
        }

        $response = new StreamedResponse();
        $response->setCallback(function () use ($content) {
            echo $content;
        });
        $response->headers->set('Content-Type', sprintf('%s; name="%s', $mimetype, $filename));
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Content-Length', strlen($content));

        return $response;
    }
}

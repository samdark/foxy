<?php

/*
 * This file is part of the Foxy package.
 *
 * (c) François Pluchino <francois.pluchino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Foxy\Tests\Solver;

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Util\Filesystem;
use Foxy\AssetManager\AssetManagerInterface;
use Foxy\Config\Config;
use Foxy\Solver\ComposerFallbackInterface;
use Foxy\Solver\Solver;
use Foxy\Solver\SolverInterface;

/**
 * Tests for solver.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
class SolverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Composer|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $composer;

    /**
     * @var Config|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $composerConfig;

    /**
     * @var WritableRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $localRepo;

    /**
     * @var IOInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $io;

    /**
     * @var Filesystem|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $fs;

    /**
     * @var InstallationManager|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $im;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $sfs;

    /**
     * @var RootPackageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $package;

    /**
     * @var AssetManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $manager;

    /**
     * @var ComposerFallbackInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $composerFallback;

    /**
     * @var string
     */
    protected $oldCwd;

    /**
     * @var string
     */
    protected $cwd;

    /**
     * @var SolverInterface
     */
    protected $solver;

    protected function setUp()
    {
        parent::setUp();

        $this->oldCwd = getcwd();
        $this->cwd = sys_get_temp_dir().DIRECTORY_SEPARATOR.'foxy_solver_test_'.uniqid();
        $this->config = new Config(array(
            'enabled' => true,
            'composer-asset-dir' => $this->cwd.'/composer-asset-dir',
        ));
        $this->composer = $this->getMockBuilder('Composer\Composer')->disableOriginalConstructor()->getMock();
        $this->composerConfig = $this->getMockBuilder('Composer\Config')->disableOriginalConstructor()->getMock();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->fs = $this->getMockBuilder('Composer\Util\Filesystem')->disableOriginalConstructor()->getMock();
        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()
            ->setMethods(array('getInstallPath'))->getMock();
        $this->sfs = new \Symfony\Component\Filesystem\Filesystem();
        $this->package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $this->manager = $this->getMockBuilder('Foxy\AssetManager\AssetManagerInterface')->getMock();
        $this->composerFallback = $this->getMockBuilder('Foxy\Solver\ComposerFallbackInterface')->getMock();
        $this->sfs->mkdir($this->cwd);
        chdir($this->cwd);

        $this->localRepo = $this->getMockBuilder('Composer\Repository\WritableRepositoryInterface')->getMock();
        $rm = new RepositoryManager($this->io, $this->composerConfig);
        $rm->setLocalRepository($this->localRepo);

        $this->composer->expects($this->any())
            ->method('getRepositoryManager')
            ->willReturn($rm);

        $this->composer->expects($this->any())
            ->method('getInstallationManager')
            ->willReturn($this->im);

        $this->composer->expects($this->any())
            ->method('getPackage')
            ->willReturn($this->package);

        $this->composer->expects($this->any())
            ->method('getConfig')
            ->willReturn($this->composerConfig);

        $sfs = $this->sfs;
        $this->fs->expects($this->any())
            ->method('findShortestPath')
            ->willReturnCallback(function ($from, $to) use ($sfs) {
                return rtrim($sfs->makePathRelative($to, $from), '/');
            });

        $this->solver = new Solver($this->manager, $this->config, $this->fs, $this->composerFallback);
    }

    protected function tearDown()
    {
        parent::tearDown();

        chdir($this->oldCwd);
        $this->sfs->remove($this->cwd);
        $this->config = null;
        $this->composer = null;
        $this->composerConfig = null;
        $this->localRepo = null;
        $this->io = null;
        $this->fs = null;
        $this->im = null;
        $this->sfs = null;
        $this->package = null;
        $this->manager = null;
        $this->composerFallback = null;
        $this->solver = null;
        $this->oldCwd = null;
        $this->cwd = null;
    }

    public function testSetUpdatable()
    {
        $this->manager->expects($this->once())
            ->method('setUpdatable')
            ->with(false);

        $this->solver->setUpdatable(false);
    }

    public function testSolveWithDisableOption()
    {
        $config = new Config(array(
            'enabled' => false,
        ));
        $solver = new Solver($this->manager, $config, $this->fs);

        $this->io->expects($this->never())
            ->method('write');

        $solver->solve($this->composer, $this->io);
    }

    public function getSolveData()
    {
        return array(
            array(0),
            array(1),
        );
    }

    /**
     * @dataProvider getSolveData
     *
     * @param int $resRunManager The result value of the run command of asset manager
     */
    public function testSolve($resRunManager)
    {
        /* @var PackageInterface|\PHPUnit_Framework_MockObject_MockObject $requirePackage */
        $requirePackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $requirePackage->expects($this->any())
            ->method('getName')
            ->willReturn('foo/bar');
        $requirePackage->expects($this->any())
            ->method('getRequires')
            ->willReturn(array(
                new Link('root/package', 'foxy/foxy'),
            ));
        $requirePackage->expects($this->any())
            ->method('getDevRequires')
            ->willReturn(array());

        $this->addInstalledPackages(array(
            $requirePackage,
        ));

        $this->io->expects($this->once())
            ->method('write');

        $requirePackagePath = $this->cwd.'/vendor/foo/bar';

        $this->im->expects($this->once())
            ->method('getInstallPath')
            ->willReturn($requirePackagePath);

        $this->manager->expects($this->exactly(2))
            ->method('getPackageName')
            ->willReturn('package.json');

        $assetPackage = $this->getMockBuilder('Foxy\AssetPackage\AssetPackageInterface')->getMock();

        $this->manager->expects($this->once())
            ->method('addDependencies')
            ->willReturn($assetPackage);

        $this->manager->expects($this->once())
            ->method('run')
            ->with($assetPackage)
            ->willReturn($resRunManager);

        if (0 === $resRunManager) {
            $this->composerFallback->expects($this->never())
                ->method('run');
        } else {
            $this->composerFallback->expects($this->once())
                ->method('run')
                ->with($this->composer, $this->io);
        }

        $requirePackageFilename = $requirePackagePath.DIRECTORY_SEPARATOR.$this->manager->getPackageName();
        $this->sfs->mkdir(dirname($requirePackageFilename));
        file_put_contents($requirePackageFilename, '{}');

        $this->solver->solve($this->composer, $this->io);
    }

    /**
     * Add the installed packages in local repository.
     *
     * @param PackageInterface[] $packages The installed packages
     */
    protected function addInstalledPackages(array $packages = array())
    {
        $this->localRepo->expects($this->any())
            ->method('getCanonicalPackages')
            ->willReturn($packages);
    }
}
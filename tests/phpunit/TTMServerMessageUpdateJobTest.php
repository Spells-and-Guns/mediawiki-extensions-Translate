<?php
/**
 * @file
 * @author David Causse
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\TtmServer\WritableTtmServer;

/**
 * Mostly test mirroring and failure modes.
 * @covers TTMServerMessageUpdateJob
 */
class TTMServerMessageUpdateJobTest extends MediaWikiIntegrationTestCase {
	/**
	 * @var WritableTtmServer[] used to link our mocks with TestableTTMServer built by the
	 * factory
	 */
	public static $mockups = [];

	protected function setUp(): void {
		parent::setUp();
		self::$mockups = [];
		$this->setMwGlobals( [
			'wgTranslateTranslationServices' => [
				'primary' => [
					'class' => TestableTTMServer::class,
					// will be used as the key in static::$mockups to attach the
					// mock to the newly created TestableTTMServer instance
					'name' => 'primary',
					'mirrors' => [ 'secondary' ],
					'type' => 'ttmserver'
				],
				'secondary' => [
					'class' => TestableTTMServer::class,
					'name' => 'secondary',
					'type' => 'ttmserver'
				]
			],
			'wgTranslateTranslationDefaultService' => 'primary'
		] );
	}

	protected function tearDown(): void {
		parent::tearDown();
		self::$mockups = [];
	}

	/**
	 * Normal mode, we ensure that update is called on primary and its mirror without any resent
	 * jobs
	 */
	public function testReplication() {
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' );
		static::$mockups['primary'] = $mock;
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' );
		static::$mockups['secondary'] = $mock;

		$job = new TestableTTMServerMessageUpdateJob(
			Title::makeTitle( NS_MAIN, 'Main Page' ),
			[ 'command' => 'refresh' ],
			$this->createMock( MessageHandle::class )
		);
		$job->run();
		$this->assertSame( [], $job->getResentJobs() );
	}

	/**
	 * The mirror failed, we ensure that we resend a job
	 * with the appropriate params.
	 */
	public function testReplicationError() {
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' );
		static::$mockups['primary'] = $mock;
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' )
			->will( $this->throwException( new TTMServerException ) );
		static::$mockups['secondary'] = $mock;

		$job = new TestableTTMServerMessageUpdateJob(
			Title::makeTitle( NS_MAIN, 'Main Page' ),
			[ 'command' => 'refresh' ],
			$this->createMock( MessageHandle::class )
		);
		$job->run();
		$this->assertCount( 1, $job->getResentJobs() );
		$expectedParams = [
			'errorCount' => 1,
			'service' => 'secondary',
			'command' => 'refresh'
		];
		$actualParams = array_intersect_key(
			$job->getResentJobs()[0]->getParams(),
			$expectedParams
		);
		$this->assertEquals( $expectedParams, $actualParams );
	}

	/**
	 * All services failed, we ensure that we resend 2 jobs for
	 * each services
	 */
	public function testAllServicesInError() {
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' )
			->will( $this->throwException( new TTMServerException ) );
		static::$mockups['primary'] = $mock;
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' )
			->will( $this->throwException( new TTMServerException ) );
		static::$mockups['secondary'] = $mock;

		$job = new TestableTTMServerMessageUpdateJob(
			Title::makeTitle( NS_MAIN, 'Main Page' ),
			[ 'command' => 'refresh' ],
			$this->createMock( MessageHandle::class )
		);
		$job->run();
		$this->assertCount( 2, $job->getResentJobs() );
		$expectedParams = [
			'errorCount' => 1,
			'service' => 'primary',
			'command' => 'refresh'
		];
		$actualParams = array_intersect_key(
			$job->getResentJobs()[0]->getParams(),
			$expectedParams
		);
		$this->assertEquals( $expectedParams, $actualParams );

		$expectedParams = [
			'errorCount' => 1,
			'service' => 'secondary',
			'command' => 'refresh'
		];
		$actualParams = array_intersect_key(
			$job->getResentJobs()[1]->getParams(),
			$expectedParams
		);
		$this->assertEquals( $expectedParams, $actualParams );
	}

	/**
	 * We simulate a resent job after a failure, this job is directed to a specific service, we
	 * ensure that we do not replicate the write to its mirror
	 */
	public function testJobOnSingleService() {
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' );
		static::$mockups['primary'] = $mock;
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->never() )
			->method( 'update' );
		static::$mockups['secondary'] = $mock;

		$job = new TestableTTMServerMessageUpdateJob(
			Title::makeTitle( NS_MAIN, 'Main Page' ),
			[
				'errorCount' => 1,
				'service' => 'primary',
				'command' => 'refresh'
			],
			$this->createMock( MessageHandle::class )
		);
		$job->run();
		$this->assertSame( [], $job->getResentJobs() );
	}

	/**
	 * We simulate a job that failed multiple times and we fail again, we encure that we adandon
	 * the job by not resending it to queue
	 */
	public function testAbandonedJob() {
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->atLeastOnce() )
			->method( 'update' )
			->will( $this->throwException( new TTMServerException ) );
		static::$mockups['primary'] = $mock;
		$mock = $this->createMock( WritableTtmServer::class );
		$mock->expects( $this->never() )
			->method( 'update' );
		static::$mockups['secondary'] = $mock;

		$job = new TestableTTMServerMessageUpdateJob(
			Title::makeTitle( NS_MAIN, 'Main Page' ),
			[
				'errorCount' => 4,
				'service' => 'primary',
				'command' => 'refresh'
			],
			$this->createMock( MessageHandle::class )
		);
		$job->run();
		$this->assertSame( [], $job->getResentJobs() );
	}

}

/**
 * Test subclass to override methods that we are not able to mock
 * easily.
 * For the context of the test we can only test the 'refresh' command
 * because other ones would need to have a more complex context to prepare
 */
class TestableTTMServerMessageUpdateJob extends TTMServerMessageUpdateJob {
	private $resentJobs = [];
	private $handleMock;

	public function __construct( Title $title, $params, $handleMock ) {
		parent::__construct( $title, $params );
		$this->handleMock = $handleMock;
	}

	public function resend( TTMServerMessageUpdateJob $job ) {
		$this->resentJobs[] = $job;
	}

	protected function getHandle() {
		return $this->handleMock;
	}

	protected function getTranslation( MessageHandle $handle ) {
		return 'random text';
	}

	public function getResentJobs() {
		return $this->resentJobs;
	}
}

/**
 * This "testable" TTMServer implementation allows to:
 * - test TTMServer specific methods
 * - attach our mocks to the Test static context, this is needed because
 *   the factory always creates a new instance of the service
 */
class TestableTTMServer extends TTMServer implements WritableTtmServer {
	private $delegate;

	public function __construct( array $config ) {
		parent::__construct( $config );
		$this->delegate = TTMServerMessageUpdateJobTest::$mockups[$config['name']];
	}

	public function update( MessageHandle $handle, ?string $targetText ): bool {
		$this->delegate->update( $handle, $targetText );
		return true;
	}

	public function beginBootstrap(): void {
		$this->delegate->beginBootstrap();
	}

	public function beginBatch(): void {
		$this->delegate->beginBatch();
	}

	public function batchInsertDefinitions( array $batch ): void {
		$this->delegate->batchInsertDefinitions( $batch );
	}

	public function batchInsertTranslations( array $batch ): void {
		$this->delegate->batchInsertTranslations( $batch );
	}

	public function endBatch(): void {
		$this->delegate->endBatch();
	}

	public function endBootstrap(): void {
		$this->delegate->endBootstrap();
	}

	public function setDoReIndex(): void {
		$this->delegate->setDoReIndex();
	}
}

<?php
/**
 * Lock manager registration handling.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup LockManager
 */
use MediaWiki\MediaWikiServices;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Rdbms\LBFactory;

/**
 * Class to handle file lock manager registration
 *
 * @ingroup LockManager
 * @since 1.19
 */
class LockManagerGroup {
	/** @var string domain (usually wiki ID) */
	protected $domain;

	/** @var LBFactory */
	protected $lbFactory;

	/** @var array Array of (name => ('class' => ..., 'config' => ..., 'instance' => ...)) */
	protected $managers = [];

	/**
	 * Do not call this directly. Use LockManagerGroupFactory.
	 *
	 * @param string $domain Domain (usually wiki ID)
	 * @param array[] $lockManagerConfigs In format of $wgLockManagers
	 * @param LBFactory $lbFactory
	 */
	public function __construct( $domain, array $lockManagerConfigs, LBFactory $lbFactory ) {
		$this->domain = $domain;
		$this->lbFactory = $lbFactory;

		foreach ( $lockManagerConfigs as $config ) {
			$config['domain'] = $this->domain;
			if ( !isset( $config['name'] ) ) {
				throw new Exception( "Cannot register a lock manager with no name." );
			}
			$name = $config['name'];
			if ( !isset( $config['class'] ) ) {
				throw new Exception( "Cannot register lock manager `{$name}` with no class." );
			}
			$class = $config['class'];
			unset( $config['class'] ); // lock manager won't need this
			$this->managers[$name] = [
				'class' => $class,
				'config' => $config,
				'instance' => null
			];
		}
	}

	/**
	 * @deprecated since 1.34, use LockManagerGroupFactory
	 *
	 * @param bool|string $domain Domain (usually wiki ID). Default: false.
	 * @return LockManagerGroup
	 */
	public static function singleton( $domain = false ) {
		return MediaWikiServices::getInstance()->getLockManagerGroupFactory()
			->getLockManagerGroup( $domain );
	}

	/**
	 * Destroy the singleton instances
	 *
	 * @deprecated since 1.34, use resetServiceForTesting() on LockManagerGroupFactory
	 */
	public static function destroySingletons() {
		MediaWikiServices::getInstance()->resetServiceForTesting( 'LockManagerGroupFactory' );
	}

	/**
	 * Get the lock manager object with a given name
	 *
	 * @param string $name
	 * @return LockManager
	 * @throws Exception
	 */
	public function get( $name ) {
		if ( !isset( $this->managers[$name] ) ) {
			throw new Exception( "No lock manager defined with the name `$name`." );
		}
		// Lazy-load the actual lock manager instance
		if ( !isset( $this->managers[$name]['instance'] ) ) {
			$class = $this->managers[$name]['class'];
			$config = $this->managers[$name]['config'];
			if ( $class === DBLockManager::class ) {
				$lb = $this->lbFactory->getMainLB( $config['domain'] );
				$config['dbServers']['localDBMaster'] = $lb->getLazyConnectionRef(
					DB_MASTER,
					[],
					$config['domain'],
					$lb::CONN_TRX_AUTOCOMMIT
				);
				$config['srvCache'] = ObjectCache::getLocalServerInstance( 'hash' );
			}
			$config['logger'] = LoggerFactory::getInstance( 'LockManager' );

			// XXX Looks like phan is right, we are trying to instantiate an abstract class and it
			// throws. Did this ever work? Presumably we need to detect the right subclass? Or
			// should we just get rid of this? It looks like it never worked since it was first
			// introduced by 0cf832a3394 in 2016, so if no one's complained until now, clearly it
			// can't be very useful?
			// @phan-suppress-next-line PhanTypeInstantiateAbstract
			$this->managers[$name]['instance'] = new $class( $config );
		}

		return $this->managers[$name]['instance'];
	}

	/**
	 * Get the config array for a lock manager object with a given name
	 *
	 * @param string $name
	 * @return array
	 * @throws Exception
	 */
	public function config( $name ) {
		if ( !isset( $this->managers[$name] ) ) {
			throw new Exception( "No lock manager defined with the name `$name`." );
		}
		$class = $this->managers[$name]['class'];

		return [ 'class' => $class ] + $this->managers[$name]['config'];
	}

	/**
	 * Get the default lock manager configured for the site.
	 * Returns NullLockManager if no lock manager could be found.
	 *
	 * XXX This looks unused, should we just get rid of it?
	 *
	 * @return LockManager
	 */
	public function getDefault() {
		return isset( $this->managers['default'] )
			? $this->get( 'default' )
			: new NullLockManager( [] );
	}

	/**
	 * Get the default lock manager configured for the site
	 * or at least some other effective configured lock manager.
	 * Throws an exception if no lock manager could be found.
	 *
	 * XXX This looks unused, should we just get rid of it?
	 *
	 * @return LockManager
	 * @throws Exception
	 */
	public function getAny() {
		return isset( $this->managers['default'] )
			? $this->get( 'default' )
			: $this->get( 'fsLockManager' );
	}
}

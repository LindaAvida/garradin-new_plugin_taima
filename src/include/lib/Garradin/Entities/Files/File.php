<?php

namespace Garradin\Entities\Files;

use KD2\Graphics\Image;
use KD2\DB\EntityManager as EM;

use Garradin\DB;
use Garradin\Entity;
use Garradin\UserException;
use Garradin\ValidationException;
use Garradin\Membres\Session;
use Garradin\Static_Cache;
use Garradin\Utils;
use Garradin\Entities\Web\Page;

use Garradin\Files\Files;

use const Garradin\{WWW_URL, ENABLE_XSENDFILE};

/**
 * This is a virtual entity, it cannot be saved to a SQL table
 */
class File extends Entity
{
	const TABLE = 'files';

	protected $id;

	/**
	 * Parent directory of file
	 */
	protected $path;

	/**
	 * File name
	 */
	protected $name;
	protected $type = self::TYPE_FILE;
	protected $mime;
	protected $size;
	protected $modified;
	protected $image;

	protected $_types = [
		'id'           => 'int',
		'path'         => 'string',
		'name'         => 'string',
		'type'         => 'int',
		'mime'         => '?string',
		'size'         => '?int',
		'modified'     => '?DateTime',
		'image'        => 'int',
	];

	const TYPE_FILE = 1;
	const TYPE_DIRECTORY = 2;
	const TYPE_LINK = 3;

	/**
	 * Tailles de miniatures autorisées, pour ne pas avoir 500 fichiers générés avec 500 tailles différentes
	 * @var array
	 */
	const ALLOWED_THUMB_SIZES = [200, 500];

	const FILE_EXT_ENCRYPTED = '.skriv.enc';
	const FILE_EXT_SKRIV = '.skriv';

	const EDITOR_WEB = 'web';
	const EDITOR_ENCRYPTED = 'encrypted';
	const EDITOR_CODE = 'code';

	const CONTEXT_DOCUMENTS = 'documents';
	const CONTEXT_USER = 'user';
	const CONTEXT_TRANSACTION = 'transaction';
	const CONTEXT_CONFIG = 'config';
	const CONTEXT_WEB = 'web';
	const CONTEXT_SKELETON = 'skel';

	const CONTEXTS_NAMES = [
		self::CONTEXT_DOCUMENTS => 'Documents',
		self::CONTEXT_USER => 'Membre',
		self::CONTEXT_TRANSACTION => 'Écriture comptable',
		self::CONTEXT_CONFIG => 'Configuration',
		self::CONTEXT_WEB => 'Site web',
		self::CONTEXT_SKELETON => 'Squelettes',
	];

	const IMAGE_TYPES = [
		'image/png',
		'image/gif',
		'image/jpeg',
	];

	const PREVIEW_TYPES = [
		'application/pdf',
		'audio/mpeg',
		'audio/ogg',
		'audio/wave',
		'audio/wav',
		'audio/x-wav',
		'audio/x-pn-wav',
		'audio/webm',
		'video/webm',
		'video/ogg',
		'application/ogg',
		'video/mp4',
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/webp',
		'image/svg+xml',
		'text/plain',
		'text/html',
	];

	const THUMB_CACHE_ID = 'file.thumb.%s.%d';

	static public function getColumns(): array
	{
		return array_keys((new self)->_types);
	}

	public function pathname(): string
	{
		$path = $this->path;

		if ($path) {
			$path .= '/';
		}

		return $path . $this->name;
	}

	public function context(): string
	{
		return strtok($this->path, '/');
	}

	public function canPreview(): bool
	{
		return in_array($this->mime, self::PREVIEW_TYPES);
	}

	public function delete(): bool
	{
		Files::callStorage('checkLock');

		// Delete recursively
		if ($this->type == self::TYPE_DIRECTORY) {
			foreach (Files::list($this->path()) as $file) {
				$file->delete();
			}
		}

		// Delete actual file content
		$return = Files::callStorage('delete', $this);

		// clean up thumbnails
		foreach (self::ALLOWED_THUMB_SIZES as $size)
		{
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $size));
		}

		return parent::delete();
	}

	public function rename(string $new_path): bool
	{
		self::validatePath($new_path);
		$current_path = $this->path();

		$return = Files::callStorage('move', $current_path, $new_path);

		$this->path = dirname($new_path);
		$this->name = basename($new_path);

		return $return;
	}

	public function setContent(string $content): self
	{
		return $this->store(null, rtrim($content));
	}

	protected function store(string $source_path = null, $source_content = null): self
	{
		if ($source_path && !$source_content)
		{
			$this->set('size', filesize($source_path));
		}
		else
		{
			$this->set('size', strlen($source_content));
		}

		// Check that it's a real image
		if ($this->image) {
			try {
				if ($source_path && !$source_content) {
					$i = new Image($source_path);
				}
				else {
					$i = Image::createFromBlob($source_content);
				}

				// Recompress PNG files from base64, assuming they are coming
				// from JS canvas which doesn't know how to gzip (d'oh!)
				if ($i->format() == 'png' && null !== $source_content) {
					$source_content = $i->output('png', true);
					$this->set('size', strlen($source_content));
				}

				unset($i);
			}
			catch (\RuntimeException $e) {
				if (strstr($e->getMessage(), 'No suitable image library found')) {
					throw new \RuntimeException('Le serveur n\'a aucune bibliothèque de gestion d\'image installée, et ne peut donc pas accepter les images. Installez Imagick ou GD.');
				}

				throw new UserException('Fichier image invalide');
			}
		}

		Files::callStorage('checkLock');

		if (!$this->exists()) {
			$this->set('modified', new \DateTime);
			$this->save();
		}

		if (!Files::callStorage('store', $this, $source_path, $source_content)) {
			throw new UserException('Le fichier n\'a pas pu être enregistré.');
		}

		// Store content in search table
		if (substr($this->mime, 0, 5) == 'text/') {
			$content = $source_content !== null ? $source_content : Files::callStorage('fetch', $this->path());

			if ($this->customType() == self::FILE_EXT_ENCRYPTED) {
				$content = 'Contenu chiffré';
			}
			else if ($this->mime === 'text/html' || $this->mime == 'text/xml') {
				$content = strip_tags($content);
			}

			// Only index contents up to 150KB and valid UTF-8
			if (strlen($content) <= 150*1024 && preg_match('//u', $content)) {
				DB::getInstance()->preparedQuery('INSERT OR REPLACE INTO files_search (path, content) VALUES (?, ?);', $this->path(), $content);
			}
		}

		return $this;
	}

	static public function createAndStore(string $path, string $name, string $source_path = null, string $source_content = null): self
	{
		$file = self::create($path, $name, $source_path, $source_content);

		$file->store($source_path, $source_content);

		return $file;
	}

	static public function create(string $path, string $name, string $source_path = null, string $source_content = null): self
	{
		if (isset($source_path, $source_content)) {
			throw new \InvalidArgumentException('Either source path or source content should be set but not both');
		}

		$finfo = \finfo_open(\FILEINFO_MIME_TYPE);
		$file = new self;
		$file->set('name', $name);
		$file->set('path', $path);

		$db = DB::getInstance();

		if ($source_path && !$source_content) {
			$file->set('mime', finfo_file($finfo, $source_path));
		}
		else {
			$file->set('mime', finfo_buffer($finfo, $source_content));
		}

		$file->set('image', (int) in_array($file->mime, self::IMAGE_TYPES));

		return $file;
	}

	/**
	 * Upload de fichier à partir d'une chaîne en base64
	 * @param  string $name
	 * @param  string $content
	 * @return File
	 */
	static public function createFromBase64(string $path, string $name, string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		return self::createAndStore($path, $name, null, $content);
	}

	public function storeFromBase64(string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		return $this->store(null, $content);
	}

	/**
	 * Upload du fichier par POST
	 */
	static public function upload(string $path, string $key): self
	{
		if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
			throw new UserException('Aucun fichier reçu');
		}

		$file = $_FILES[$key];

		if (!empty($file['error'])) {
			throw new UserException(self::getErrorMessage($file['error']));
		}

		if (empty($file['size']) || empty($file['name'])) {
			throw new UserException('Fichier reçu invalide : vide ou sans nom de fichier.');
		}

		if (!is_uploaded_file($file['tmp_name'])) {
			throw new \RuntimeException('Le fichier n\'a pas été envoyé de manière conventionnelle.');
		}

		$name = preg_replace('/\s+/', '_', $file['name']);
		$name = preg_replace('/[^\d\w._-]/ui', '', $name);

		return self::createAndStore($path, $name, $file['tmp_name']);
	}


	/**
	 * Récupération du message d'erreur
	 * @param  integer $error Code erreur du $_FILE
	 * @return string Message d'erreur
	 */
	static public function getErrorMessage($error)
	{
		switch ($error)
		{
			case UPLOAD_ERR_INI_SIZE:
				return 'Le fichier excède la taille permise par la configuration du serveur.';
			case UPLOAD_ERR_FORM_SIZE:
				return 'Le fichier excède la taille permise par le formulaire.';
			case UPLOAD_ERR_PARTIAL:
				return 'L\'envoi du fichier a été interrompu.';
			case UPLOAD_ERR_NO_FILE:
				return 'Aucun fichier n\'a été reçu.';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'Pas de répertoire temporaire pour stocker le fichier.';
			case UPLOAD_ERR_CANT_WRITE:
				return 'Impossible d\'écrire le fichier sur le disque du serveur.';
			case UPLOAD_ERR_EXTENSION:
				return 'Une extension du serveur a interrompu l\'envoi du fichier.';
			default:
				return 'Erreur inconnue: ' . $error;
		}
	}

	public function url($download = false): string
	{
		if ($this->context() == self::CONTEXT_WEB) {
			$path = substr($this->path, strlen(self::CONTEXT_WEB . '/'));
		}
		else {
			$path = $this->path;
		}

		$url = WWW_URL . $path . '/' . $this->name;

		if ($download) {
			$url .= '?download';
		}

		return $url;
	}

	public function thumb_url(?int $size = null): string
	{
		$size = $size ? self::_findNearestThumbSize($size) : min(self::ALLOWED_THUMB_SIZES);
		return sprintf('%s?%dpx', $this->url(), $size);
	}

	/**
	 * Renvoie la taille de miniature la plus proche de la taille demandée
	 * @param  integer $size Taille demandée
	 * @return integer       Taille possible
	 */
	static protected function _findNearestThumbSize($size)
	{
		$size = (int) $size;

		if (in_array($size, self::ALLOWED_THUMB_SIZES))
		{
			return $size;
		}

		foreach (self::ALLOWED_THUMB_SIZES as $s)
		{
			if ($s >= $size)
			{
				return $s;
			}
		}

		return max(self::ALLOWED_THUMB_SIZES);
	}

	/**
	 * Envoie le fichier au client HTTP
	 */
	public function serve(?Session $session = null, bool $download = false): void
	{
		if (!$this->checkReadAccess($session)) {
		var_dump($this); exit;
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Vous n\'avez pas accès à ce fichier.');
			return;
		}

		$path = Files::callStorage('getFullPath', $this);
		$content = null === $path ? Files::callStorage('fetch', $this) : null;

		$this->_serve($path, $content, $download);
	}

	/**
	 * Envoie une miniature à la taille indiquée au client HTTP
	 */
	public function serveThumbnail(?Session $session = null, ?int $width = null): void
	{
		if (!$this->checkReadAccess($session)) {
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Accès interdit');
			return;
		}

		if (!$this->image) {
			throw new UserException('Il n\'est pas possible de fournir une miniature pour un fichier qui n\'est pas une image.');
		}

		if (!$width) {
			$width = reset(self::ALLOWED_THUMB_SIZES);
		}

		if (!in_array($width, self::ALLOWED_THUMB_SIZES)) {
			throw new UserException('Cette taille de miniature n\'est pas autorisée.');
		}

		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $width);
		$destination = Static_Cache::getPath($cache_id);

		// La miniature n'existe pas dans le cache statique, on la crée
		if (!Static_Cache::exists($cache_id))
		{
			try {
				if ($path = Files::callStorage('getFullPath', $this)) {
					(new Image($path))->resize($width)->save($destination);
				}
				elseif ($content = Files::callStorage('fetch', $this)) {
					Image::createFromBlob($content)->resize($width)->save($destination);
				}
				else {
					throw new \RuntimeException('Unable to fetch file');
				}
			}
			catch (\RuntimeException $e) {
				throw new UserException('Impossible de créer la miniature');
			}
		}

		$this->_serve($destination, null);
	}

	/**
	 * Servir un fichier local en HTTP
	 * @param  string $path Chemin vers le fichier local
	 * @param  string $type Type MIME du fichier
	 * @param  string $name Nom du fichier avec extension
	 * @param  integer $size Taille du fichier en octets (facultatif)
	 * @return boolean TRUE en cas de succès
	 */
	protected function _serve(?string $path, ?string $content, bool $download = false): void
	{
		if ($this->isPublic()) {
			Utils::HTTPCache(null, $this->modified->getTimestamp());
		}
		else {
			// Disable browser cache
			header('Pragma: private');
			header('Expires: -1');
			header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
		}

		$type = $this->mime;

		// Force CSS mimetype
		if (substr($this->name, -4) == '.css') {
			$type = 'text/css';
		}
		elseif (substr($this->name, -3) == '.js') {
			$type = 'text/javascript';
		}

		if (substr($type, 0, 5) == 'text/') {
			$type .= ';charset=utf-8';
		}

		header(sprintf('Content-Type: %s', $type));
		header(sprintf('Content-Disposition: %s; filename="%s"', $download ? 'attachment' : 'inline', $this->name));

		// Utilisation de XSendFile si disponible
		if (null !== $path && ENABLE_XSENDFILE && isset($_SERVER['SERVER_SOFTWARE']))
		{
			if (stristr($_SERVER['SERVER_SOFTWARE'], 'apache')
				&& function_exists('apache_get_modules')
				&& in_array('mod_xsendfile', apache_get_modules()))
			{
				header('X-Sendfile: ' . $path);
				return;
			}
			else if (stristr($_SERVER['SERVER_SOFTWARE'], 'lighttpd'))
			{
				header('X-Sendfile: ' . $path);
				return;
			}
		}

		// Désactiver gzip
		if (function_exists('apache_setenv'))
		{
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		header(sprintf('Content-Length: %d', $path ? filesize($path) : strlen($content)));

		if (@ob_get_length()) {
			@ob_clean();
		}

		flush();

		if (null !== $path) {
			readfile($path);
		}
		else {
			echo $content;
		}
	}

	public function fetch()
	{
		return Files::callStorage('fetch', $this);
	}

	public function render(array $options = [])
	{
		$type = $this->customType();
		/*
		if (substr($this->name, -strlen(self::FILE_EXT_HTML)) == self::FILE_EXT_HTML) {
			return \Garradin\Web\Render\HTML::render($this, null, $options);
		}*/

		if ($type == self::FILE_EXT_SKRIV) {
			return \Garradin\Web\Render\Skriv::render($this, null, $options);
		}
		else if ($type == self::FILE_EXT_ENCRYPTED) {
			return \Garradin\Web\Render\EncryptedSkriv::render($this, null, $options);
		}

		throw new \LogicException('Unknown render type: ' . $type);
	}

	public function checkReadAccess(?Session $session): bool
	{
		// Web pages and config files are always public
		if ($this->isPublic()) {
			return true;
		}

		$context = $this->context();

		if (null === $session) {
			return false;
		}

		if ($context == self::CONTEXT_TRANSACTION && $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)) {
			return true;
		}
		// The user can access his own profile files
		else if ($context == self::CONTEXT_USER && $ref == $session->getUser()->id) {
			return true;
		}
		// Only users able to manage users can see their profile files
		else if ($context == self::CONTEXT_USER && $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE)) {
			return true;
		}
		// Only users with right to access documents can read documents
		else if ($context == self::CONTEXT_DOCUMENTS && $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_READ)) {
			return true;
		}

		return false;
	}

	public function checkWriteAccess(?Session $session): bool
	{
		if (null === $session) {
			return false;
		}

		switch ($this->context()) {
			case self::CONTEXT_WEB:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);
			case self::CONTEXT_DOCUMENTS:
				// Only admins can delete files
				return $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_WRITE);
			case self::CONTEXT_CONFIG:
				return $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN);
			case self::CONTEXT_TRANSACTION:
				return $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE);
			case self::CONTEXT_SKELETON:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN);
			case self::CONTEXT_USER:
				return $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE);
		}

		return false;
	}

	public function checkDeleteAccess(?Session $session): bool
	{
		if (null === $session) {
			return false;
		}

		switch ($this->context()) {
			case self::CONTEXT_WEB:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);
			case self::CONTEXT_DOCUMENTS:
				// Only admins can delete files
				return $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_ADMIN);
			case self::CONTEXT_CONFIG:
				return $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN);
			case self::CONTEXT_TRANSACTION:
				return $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_ADMIN);
			case self::CONTEXT_SKELETON:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN);
			case self::CONTEXT_USER:
				return $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE);
		}

		return false;
	}

	public function path(): string
	{
		return $this->path . '/' . $this->name;
	}

	public function pathHash(): string
	{
		return sha1($this->path());
	}

	public function isPublic(): bool
	{
		$context = $this->context();

		if ($context == self::CONTEXT_CONFIG || $context == self::CONTEXT_WEB || $context == self::CONTEXT_SKELETON) {
			return true;
		}

		return false;
	}

	public function getEditor(): ?string
	{
		if ($this->customType() == self::FILE_EXT_SKRIV) {
			return self::EDITOR_WEB;
		}
		elseif ($this->customType() == self::FILE_EXT_ENCRYPTED) {
			return self::EDITOR_ENCRYPTED;
		}
		elseif (substr($this->mime, 0, 5) == 'text/') {
			return self::EDITOR_CODE;
		}

		return null;
	}

	static public function validatePath(string $path): array
	{
		$path = explode('/', $path);

		if (count($path) < 1) {
			throw new ValidationException('Invalid file path');
		}

		if (!array_key_exists($path[0], self::CONTEXTS_NAMES)) {
			throw new ValidationException('Chemin invalide');
		}

		$context = array_shift($path);

		foreach ($path as $part) {
			if (!preg_match('!^[\w\d_-]+(?:\.[\w\d_-]+)*$!iu', $part)) {
				throw new ValidationException('Chemin invalide');
			}
		}

		$name = array_pop($path);
		$ref = implode('/', $path);
		return [$context, $ref ?: null, $name];
	}

	public function customType(): ?string
	{
		static $extensions = [self::FILE_EXT_ENCRYPTED, self::FILE_EXT_SKRIV];

		foreach ($extensions as $ext) {
			if (substr($this->name, -strlen($ext)) == $ext) {
				return $ext;
			}
		}

		return null;
	}
}

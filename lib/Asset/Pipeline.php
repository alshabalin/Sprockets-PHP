<?php
namespace Asset;

class Pipeline
{
	static private $current_instance,
		$filters = array();
	private $base_directories,
		$files,
		$directories,
		$processed_files = array(),
		$dependencies,
		$main_file_name = 'application';
	const DEPTH = -1;

	public function __construct($base_directories)
	{
		$this->base_directories = (array) $base_directories;
		$this->listFilesAndDirectories();
	}

	public function __invoke($t,$m=null,$v=array()){return $this->process($t,$m,$v);}
	public function process($type, $main_file = null, $vars = array())
	{
		if (self::$current_instance)
			throw new \RuntimeException('There is still a Pipeline instance running');
		self::$current_instance = $this;
		
		if ($main_file) //this if is why $this->main_file_name is used for File::__construct() below
			$this->main_file_name = $main_file;
		
		$content = (string) new File($this->main_file_name . '.' . $type, $vars);
		
		self::$current_instance = null;
		
		return $content;
	}
	
	public function getMainFile($type)
	{
		return $this->getFile($this->main_file_name, $type);
	}
	
	public function getBaseDirectories()
	{
		return $this->base_directories;
	}

	public function hasFile($name, $type)
	{
		return isset($this->files[$type][$name]);
	}
	
	public function getFile($name, $type)
	{
		if (isset($this->files[$type][$name]))
			return $this->files[$type][$name];
		
		throw new Exception\FileNotFound($name, $type);
	}
	
	public function hasDirectory($name)
	{
		return isset($this->directories[$name]);
	}
	
	public function getDirectory($name)
	{
		if ('' === trim($name, '/.'))
			return true;

		if (isset($this->directories[$name]))
			return $this->directories[$name];
		
		throw new Exception\DirectoryNotFound($name);
	}
	
	public function hasProcessedFile($file)
	{
		if (isset($this->processed_files[$file]))
			return true;

		$this->processed_files[$file] = true;
	}
	
	public function getFilesUnder($directory, $type, $depth_limit = -1)
	{
		$files = array();

		if ($directory == '.')
			$directory = '';
		$directory_length = strlen($directory);

		foreach ($this->files[$type] as $name => $path)
		{
			if (isset($this->processed_files[$path]))
				continue;

			if (substr($name, 0, $directory_length) == $directory)
			{ //it starts with the right directory
				$relative_path = trim(substr($name, $directory_length), '/');
				$depth = count(explode('/', $relative_path));
				
				if (-1 != $depth_limit && $depth > $depth_limit)
					//it's not too far
					continue;

				$files[] = $name;
			}
		}

		return $files;
	}
	
	public function addDependency($path)
	{
		if (null === $this->dependencies)
			$this->dependencies = array();
		else if (!isset($this->dependencies[$path]))
			//in order to not register the first file
			$this->dependencies[$path] = true;
	}
	
	public function getDependencies()
	{
		return array_keys($this->dependencies);
	}
	
	public function getDependenciesFileContent()
	{
		$hash = array();
		
		foreach ($this->getDependencies() as $dependency)
			$hash[] = $dependency . ':' . filemtime($dependency);
			
		return implode("\n", $hash);
	}
	
	public function applyFilter($content, $filter, $file, $vars)
	{
		$filter = $this->getFilter($filter);
		return $filter($content, $file, $vars);
	}
	
	private function getFilter($name)
	{
		if (!isset(self::$filters[$name]))
		{
			$class = 'Filter\\' . ucfirst($name);
			self::$filters[$name] = new $class;
		}
		
		return self::$filters[$name];
	}

	private function listFilesAndDirectories()
	{
		$files = array();
		$directories = array();
		
		$flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;
		foreach ($this->base_directories as $base_directory)
		{
			$base_directory_length = strlen($base_directory);

			$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base_directory . '/', $flags),
			 \RecursiveIteratorIterator::CHILD_FIRST); //include directories
			if (self::DEPTH != -1)
				$it->setMaxDepth(self::DEPTH);

			while ($it->valid())
			{
				if ($it->isLink())
				{
					$it->next();
					continue;
				}

				$name = ltrim(substr($it->key(), $base_directory_length), '/');
				$path = $base_directory . '/' . $name;

				if ($it->isDir())
					$directories[$name] = $path;
				else
				{
					$name_parts = explode('.', $name);
					$name = $name_parts[0];
					$type = $name_parts[1];

					$files[$type][$name] = $path;
				}

				$it->next();
			}
		}
		
		$this->files = $files;
		$this->directories = $directories;
	}

	static public function getCurrentInstance()
	{
		if (!self::$current_instance)
			throw new \RuntimeException('There is no Pipeline instance running');
			
		return self::$current_instance;
	}
}
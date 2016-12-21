<?php namespace exface\Core\CommonLogic;

use exface\Core\Interfaces\DebuggerInterface;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Debug\ExceptionHandler;
use Symfony\Component\Debug\Exception\FlattenException;

class Debugger implements DebuggerInterface {
	
	private $prettify_errors = false;
	
	public function print_exception(\Throwable $exception, $use_html = true){
		$handler = new ExceptionHandler();
		$flattened_exception = FlattenException::create($exception);
		$output = "<style>" . $handler->getStylesheet($flattened_exception) . " #sf-resetcontent {padding: 10px;} h2.block_exception.clear_fix {margin: 0}</style>" . $handler->getContent($flattened_exception);
		return $output;
	}
	
	public function get_prettify_errors() {
		return $this->prettify_errors;
	}
	
	public function set_prettify_errors($value) {
		$this->prettify_errors = $value ? true : false;
		if ($this->prettify_errors){
			$this->register_handler();
		}
		return $this;
	}
	
	protected function register_handler(){
		Debug::enable(E_ALL & ~E_NOTICE);
	}
	  
}

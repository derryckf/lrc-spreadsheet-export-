<?php
declare(strict_types=1);
namespace myTest\utility;
/**
 * windowed linear regression function
 * constructor used to set length of window
 * @param $x array x-coords
 * @param $y array y-coords
 * @returns array() m=>slope, b=>intercept
 */

class linearRegression
{
    public array $X = [];

    public array $Y = [];
    
    private $size;
    
    public function __construct(int $size = 3 )
    {
        $this->size = ($size > 1)? $size: 2; // makes no sense unless there is at least 2 points
    }
    
    
    protected function init()
    {
        //do nothing
    }
      
    public function add($x, $y)
    {
        $this->X[] = $x;
        $this->Y[] = $y;
        while (count($this->X) > $this->size)
        {
            array_shift($this->X);
            array_shift($this->Y);
        }
    }
    
    public function reset()
    {
        unset($this->X);
        unset($this->Y);
    }
   
    function linear_regression() {

      // calculate number points
      $n = count($this->X);

      if( $n <= 2 )
          return array("m"=>0, "b"=>0, "sd"=>0); // prevent divide by zero 

      // calculate sums
      $x_sum = array_sum($this->X);
      $y_sum = array_sum($this->Y);

      $xx_sum = 0;
      $xy_sum = 0;

      for($i = 0; $i < $n; $i++) {

        $xy_sum+=($this->X[$i]*$this->Y[$i]);
        $xx_sum+=($this->X[$i]*$this->X[$i]);

      }

      // calculate slope
      $divisor = (($n * $xx_sum) - ($x_sum * $x_sum));
      if( $divisor != 0)
      {
           $m = (($n * $xy_sum) - ($x_sum * $y_sum)) / $divisor;
      }
      else
          $m = 0.0;

      // calculate intercept
      $b = ($y_sum - ($m * $x_sum)) / $n;

      // calculate residuals
      $residuals = [];
      for ($i = 0; $i < $n; $i++) {
          $residuals[] = $this->Y[$i] - ($m * $this->X[$i]) - $b;
      }

      // calculate standard deviation of residuals
      $sd = sqrt(array_sum(array_map(function($residual) {
          return pow($residual, 2);
      }, $residuals)) / ($n - 2));

      // return result
      return array("m"=>$m, "b"=>$b, "sd"=>$sd);

    }

    function standardDeviation() {
      return $this->linear_regression()['sd'];
    }
}

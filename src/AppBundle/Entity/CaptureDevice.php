<?php
// src/AppBundle/Entity/CaptureDevice.php
namespace AppBundle\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\DBAL\Driver\Connection;

class CaptureDevice
{
    
    /**
     * @Assert\NotBlank()
     * @var int
     */
    public $capture_data_element_id;

    /**
     * @Assert\NotBlank()
     * @Assert\Length(min="1", max="255")
     * @var string
     */
    public $calibration_file;

}
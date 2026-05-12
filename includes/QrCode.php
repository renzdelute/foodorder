<?php

namespace App\Includes;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Result\Result;
use Endroid\QrCode\Writer\WriterInterface;

/**
 * QR Code utility for generating QR codes for the food order system
 */
class QrCodeGenerator
{
    /**
     * Generate a QR code for table access
     * 
     * @param int $tableId The table identifier
     * @param string $baseUrl Base URL for the ordering system
     * @return string Base64 encoded PNG image
     */
    public static function generateTableQrCode(int $tableId, string $baseUrl): string
    {
        $url = $baseUrl . '/table.php?table_id=' . $tableId;
        
        $qrCode = new QrCode($url);
        $qrCode->setSize(300);
        $qrCode->setMargin(10);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());
        $qrCode->setForegroundColor(new Color(0, 0, 0));
        $qrCode->setBackgroundColor(new Color(255, 255, 255));
        
        // Add label
        $label = new Label('Scan to order from Table #' . $tableId);
        $label->setTextColor(new Color(0, 0, 0));
        $label->setFontSize(16);
        $label->setPadding(10);
        $qrCode->setLabel($label);
        
        $writer = new PngWriter();
        /** @var Result $result */
        $result = $writer->write($qrCode);
        
        return base64_encode($result->getString());
    }
    
    /**
     * Generate a QR code for kitchen display (order details)
     * 
     * @param int $orderId The order identifier
     * @param string $baseUrl Base URL for the kitchen display system
     * @return string Base64 encoded PNG image
     */
    public static function generateKitchenQrCode(int $orderId, string $baseUrl): string
    {
        $url = $baseUrl . '/kitchen.php?order_id=' . $orderId;
        
        $qrCode = new QrCode($url);
        $qrCode->setSize(250);
        $qrCode->setMargin(10);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());
        $qrCode->setForegroundColor(new Color(0, 0, 0));
        $qrCode->setBackgroundColor(new Color(255, 255, 255));
        
        // Add label
        $label = new Label('Order #' . $orderId . ' - Kitchen Display');
        $label->setTextColor(new Color(0, 0, 0));
        $label->setFontSize(14);
        $label->setPadding(8);
        $qrCode->setLabel($label);
        
        $writer = new PngWriter();
        /** @var Result $result */
        $result = $writer->write($qrCode);
        
        return base64_encode($result->getString());
    }
    
    /**
     * Generate a QR code for customer order tracking
     * 
     * @param string $orderCode The order code
     * @param string $baseUrl Base URL for the tracking system
     * @return string Base64 encoded PNG image
     */
    public static function generateTrackingQrCode(string $orderCode, string $baseUrl): string
    {
        $url = $baseUrl . '/public/index.php?order_code=' . urlencode($orderCode);
        
        $qrCode = new QrCode($url);
        $qrCode->setSize(250);
        $qrCode->setMargin(10);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());
        $qrCode->setForegroundColor(new Color(0, 0, 0));
        $qrCode->setBackgroundColor(new Color(255, 255, 255));
        
        // Add label
        $label = new Label('Track Order: ' . $orderCode);
        $label->setTextColor(new Color(0, 0, 0));
        $label->setFontSize(14);
        $label->setPadding(8);
        $qrCode->setLabel($label);
        
        $writer = new PngWriter();
        /** @var Result $result */
        $result = $writer->write($qrCode);
        
        return base64_encode($result->getString());
    }
    
    /**
     * Save QR code to file
     * 
     * @param string $base64Image Base64 encoded PNG image
     * @param string $filePath Path to save the image
     * @return bool True on success
     */
    public static function saveQrCode(string $base64Image, string $filePath): bool
    {
        $imageData = base64_decode($base64Image);
        $file = fopen($filePath, 'wb');
        if ($file === false) {
            return false;
        }
        fwrite($file, $imageData);
        fclose($file);
        return true;
    }
}
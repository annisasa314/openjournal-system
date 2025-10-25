<?php

/**
 * Copyright (c) 2015 University of Pittsburgh
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace COUNTER {
    // ... (namespace and class comments remain the same)

    /**
     * COUNTER report builder class
     * Other classes in this package extend from this core class to have access to several generic functions
     *
     * @todo should "tool-like" functions be moved to static calls from an non-inherited Tools class?
     */
    class ReportBuilder
    {
        public const COUNTER_NAMESPACE = 'http://www.niso.org/schemas/counter';

        // ... (other methods remain the same)

        /**
         * Output this object as a DOMDocument
         * This method must be implemented in the subclass
         *
         * @throws Exception
         */
        public function asDOMDocument()
        {
            throw new \Exception(get_class($this) . ' does not implement asDOMDocument()');
        }

        /**
         * Do NOT build this object
         * This method must be implemented in the subclass
         * Subclasses should call this method if unable to build the object in order to report an error.
         *
         * @throws Exception
         */
        public static function build($array)
        {
            throw new \Exception('Failed to build ' . static::class . ' from data ' . var_export($array, true));
        }
    }

    /**
     * COUNTER report instance class
     */
    class Report extends ReportBuilder
    {
        /**
         * @var string Report attribute "Created"
         */
        private $created;
        /**
         * @var string Report attribute "ID"
         */
        private $id;
        /**
         * @var string Report attribute "Version"
         */
        private $version;
        /**
         * @var string Report attribute "Name"
         */
        private $name;
        /**
         * @var string Report attribute "Title"
         */
        private $title;
        /**
         * @var COUNTER\Vendor
         */
        private $vendor;
        /**
         * @var array one or more COUNTER\Customer objects
         */
        private $customer;

        /**
         * Construct the object
         *
         * @param string $id
         * @param string $version
         * @param string $name
         * @param string $title
         * @param string $customers COUNTER\Customer
         * @param object $vendor COUNTER\Vendor array
         * @param string $created optional
         *
         * @throws Exception
         */
        public function __construct($id, $version, $name, $title, $customers, $vendor, $created = '')
        {
            // Replace variable variables with explicit assignments
            $this->id = $this->validateString($id);
            $this->version = $this->validateString($version);
            $this->name = $this->validateString($name);
            $this->title = $this->validateString($title);
            $this->created = $this->validateString($created);
            
            if (!$this->created) {
                $this->created = date("Y-m-d\Th:i:sP");
            }
            $this->vendor = $this->validateOneOf($vendor, 'Vendor');
            $this->customer = $this->validateOneOrMoreOf($customers, 'Customer');
        }

        // ... (build method and other methods remain the same)

        /**
         * Output this object as a DOMDocument
         *
         * @return DOMDocument
         */
        public function asDOMDocument()
        {
            $doc = new \DOMDocument();
            $root = $doc->appendChild($doc->createElement('Report'));
            
            // Create a mapping of attribute names to property values
            $attributes = [
                'Created' => $this->created,
                'ID' => $this->id,
                'Version' => $this->version,
                'Name' => $this->name,
                'Title' => $this->title
            ];
            
            foreach ($attributes as $attrName => $attrValue) {
                $attrAttr = $doc->createAttribute($attrName);
                $attrAttr->appendChild($doc->createTextNode($attrValue));
                $root->appendChild($attrAttr);
            }
            
            $root->appendChild($doc->importNode($this->vendor->asDOMDocument()->documentElement, true));
            foreach ($this->customer as $customer) {
                $root->appendChild($doc->importNode($customer->asDOMDocument()->documentElement, true));
            }
            return $doc;
        }
    }

    /**
     * COUNTER vendor class
     */
    class Vendor extends ReportBuilder
    {
        /**
         * @var string Vendor element "Name"
         */
        private $name;
        /**
         * @var string Vendor element "ID"
         */
        private $id;
        /**
         * @var array zero or more COUNTER\Contact elements
         */
        private $contact = [];
        /**
         * @var string Vendor element "WebSiteUrl"
         */
        private $webSiteUrl;
        /**
         * @var string Vendor element "LogoUrl"
         */
        private $logoUrl;

        /**
         * Construct the object
         *
         * @param string $id
         * @param string $name optional
         * @param array $contacts optional COUNTER\Contact array
         * @param string $webSiteUrl optional
         * @param string $logoUrl optional
         *
         * @throws Exception
         */
        public function __construct($id, $name = '', $contacts = [], $webSiteUrl = '', $logoUrl = '')
        {
            // Replace variable variables with explicit assignments
            $this->id = $this->validateString($id);
            $this->name = $this->validateString($name);
            $this->webSiteUrl = $this->validateString($webSiteUrl);
            $this->logoUrl = $this->validateString($logoUrl);
            
            $this->contact = $this->validateZeroOrMoreOf($contacts, 'Contact');
        }

        // ... (build method remains the same)

        /**
         * Output this object as a DOMDocument
         *
         * @return DOMDocument
         */
        public function asDOMDocument()
        {
            $doc = new \DOMDocument();
            $root = $doc->appendChild($doc->createElement('Vendor'));
            if ($this->name) {
                $root->appendChild($doc->createElement('Name'))->appendChild($doc->createTextNode($this->name));
            }
            $root->appendChild($doc->createElement('ID'))->appendChild($doc->createTextNode($this->id));
            if ($this->contact) {
                foreach ($this->contact as $contact) {
                    $root->appendChild($doc->importNode($contact->asDOMDocument()->documentElement, true));
                }
            }
            if ($this->webSiteUrl) {
                $root->appendChild($doc->createElement('WebSiteUrl'))->appendChild($doc->createTextNode($this->webSiteUrl));
            }
            if ($this->logoUrl) {
                $root->appendChild($doc->createElement('LogoUrl'))->appendChild($doc->createTextNode($this->logoUrl));
            }
            return $doc;
        }
    }

    // ... (other classes remain unchanged - Contact, Customer, Consortium, ReportItems, ParentItem, 
    // ItemContributor, ItemContributorId, Identifier, ItemDate, ItemAttribute, Metric, DateRange, PerformanceCounter)

    /**
     * COUNTER performance counter class
     */
    class PerformanceCounter extends ReportBuilder
    {
        /**
         * @var COUNTER\MetricType PerformanceCounter element "MetricType"
         */
        private $metricType;
        /**
         * @var int PerformanceCounter element "Count"
         */
        private $count;

        /**
         * Construct the object
         *
         * @param string $metricType
         * @param int $count
         *
         * @throws Exception
         */
        public function __construct($metricType, $count)
        {
            $this->metricType = $this->validateString($metricType);
            if (!in_array($metricType, $this->getMetricTypes())) {
                throw new \Exception('Invalid metric type: ' . $metricType);
            }
            $this->count = $this->validatePositiveInteger($count);
        }

        /**
         * Construct the object from an array
         *
         * @param array $array Hash of key-values
         *
         * @throws Exception
         *
         * @return \self
         */
        public static function build($array)
        {
            if (is_array($array)) {
                if (isset($array['MetricType']) && isset($array['Count'])) {
                    // Nicely structured associative array
                    return new self($array['MetricType'], $array['Count']);
                }
                if (count(array_keys($array)) == 1 && parent::isAssociative($array)) {
                    // Loosely structured associative array (type => count)
                    foreach ($array as $k => $v) {
                        return new self($k, $v);
                    }
                }
            }
            parent::build($array);
        }

        /**
         * Output this object as a DOMDocument
         *
         * @return DOMDocument
         */
        public function asDOMDocument()
        {
            $doc = new \DOMDocument();
            $root = $doc->appendChild($doc->createElement('Instance'));
            $root->appendChild($doc->createElement('MetricType'))->appendChild($doc->createTextNode($this->metricType));
            $root->appendChild($doc->createElement('Count'))->appendChild($doc->createTextNode($this->count));
            return $doc;
        }
    }

}
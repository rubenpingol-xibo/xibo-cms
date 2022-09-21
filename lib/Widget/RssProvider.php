<?php
/*
 * Copyright (C) 2022 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Xibo\Widget;

use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use PicoFeed\Config\Config;
use PicoFeed\Logging\Logger;
use PicoFeed\Parser\Item;
use PicoFeed\PicoFeedException;
use PicoFeed\Reader\Reader;
use Xibo\Helper\Environment;
use Xibo\Support\Exception\ConfigurationException;
use Xibo\Support\Exception\InvalidArgumentException;
use Xibo\Widget\DataType\Article;
use Xibo\Widget\Provider\DataProviderInterface;
use Xibo\Widget\Provider\DurationProviderInterface;
use Xibo\Widget\Provider\WidgetProviderInterface;
use Xibo\Widget\Provider\WidgetProviderTrait;

/**
 * Downloads a RSS feed and returns Article data types
 */
class RssProvider implements WidgetProviderInterface
{
    use WidgetProviderTrait;

    public function fetchData(DataProviderInterface $dataProvider): WidgetProviderInterface
    {
        $uri = $dataProvider->getProperty('uri');
        if (empty($uri)) {
            throw new InvalidArgumentException(__('Please enter a the URI to a valid RSS feed.'), 'uri');
        }

        $picoFeedLoggingEnabled = Environment::isDevMode();

        // Date format for the feed items
        $dateFormat = $dataProvider->getProperty('dateFormat');
        $expiresImage = Carbon::now()
            ->addMinutes($dataProvider->getProperty('updateIntervalImages', 1440))
            ->format('U');

        try {
            $response = $dataProvider
                ->getGuzzleClient([
                    'headers' => [
                        'Accept' => 'application/rss+xml, application/rdf+xml;q=0.8, application/atom+xml;q=0.6,'
                            . 'application/xml;q=0.4, text/xml;q=0.4, text/html;q=0.2, text/*;q=0.1'
                    ],
                    'timeout' => 20, // wait no more than 20 seconds
                ])
                ->get($uri);

            // Pull out the content type
            $contentType = $response->getHeaderLine('Content-Type');

            $this->getLog()->debug('Feed returned content-type ' . $contentType);

            // https://github.com/xibosignage/xibo/issues/1401
            if (stripos($contentType, 'rss') === false
                && stripos($contentType, 'xml') === false
                && stripos($contentType, 'text') === false
                && stripos($contentType, 'html') === false
            ) {
                // The content type isn't compatible
                $this->getLog()->error('Incompatible content type: ' . $contentType);
                return $this;
            }

            // Get the body, etc
            $result = explode('charset=', $contentType);
            $document['encoding'] = $result[1] ?? '';
            $document['xml'] = $response->getBody()->getContents();

            $this->getLog()->debug('Feed downloaded.');

            // Load the feed XML document into a feed parser
            // Enable logging if we need to
            if ($picoFeedLoggingEnabled) {
                $this->getLog()->debug('Setting Picofeed Logger to Enabled.');
                Logger::enable();
            }

            // Allowable attributes
            $clientConfig = new Config();

            // need a sensible way to set this
            // https://github.com/fguillot/picoFeed/issues/196
            //if ($dataProvider->getProperty('allowedAttributes') != null) {
            //$clientConfig->setFilterWhitelistedTags(explode(',', $dataProvider->getProperty('allowedAttributes')));
            //}

            // Get the feed parser
            $reader = new Reader($clientConfig);
            $parser = $reader->getParser($uri, $document['xml'], $document['encoding']);

            // Get a feed object
            $feed = $parser->execute();

            // Get all items
            $feedItems = $feed->getItems();

            // Parse each item into an article
            foreach ($feedItems as $item) {
                /* @var Item $item */
                $article = new Article();
                $article->title = $item->getTitle();
                $article->content = $item->getContent();
                $article->author = $item->getAuthor();
                $article->link = $item->getUrl();
                $article->date = Carbon::instance($item->getDate());
                $article->publishedDate = Carbon::instance($item->getPublishedDate());

                // RSS doesn't support a summary/excerpt tag.
                $descriptionTag = $item->getTag('description');
                if (!$descriptionTag) {
                    $article->summary = strip_tags($article->content);
                } else {
                    $article->summary = $descriptionTag[0];
                }

                // Do we have an image included?
                if (stripos($item->getEnclosureType(), 'image') > -1) {
                    $link = $item->getEnclosureUrl();

                    if (!(empty($link))) {
                        $article->image = $dataProvider->addImage('ticker_ ' . md5($link), $link, $expiresImage);
                    } else {
                        $this->getLog()->debug('No image found for image tag using getEnclosureUrl');
                    }
                }

                if ($dataProvider->getProperty('decodeHtml') == 1) {
                    $article->content = htmlspecialchars_decode($article->content);
                }

                if ($dataProvider->getProperty('stripTags') != '') {
                    $doc = new \DOMDocument();
                    $doc->loadHTML($article->content);
                    $xpath = new \DOMXPath($doc);

                    foreach (explode(',', $dataProvider->getProperty('stripTags')) as $forbidden) {
                        foreach ($xpath->query('//' . $forbidden) as $node) {
                            $node->parentNode->removeChild($node);
                        }
                    }
                    $article->content = $doc->saveHTML();
                }

                // TODO: how do we support non-standard RSS nodes? (do we?)

                // Add the article.
                $dataProvider->addItem($article);
            }
        } catch (RequestException $requestException) {
            // Log and return empty?
            $this->getLog()->error('Unable to get feed: ' . $uri
                . ', e: ' . $requestException->getMessage());
            throw new ConfigurationException(__('Unable to download feed'));
        } catch (PicoFeedException $picoFeedException) {
            // Output any PicoFeed logs
            if ($picoFeedLoggingEnabled) {
                $this->getLog()->debug('Outputting Picofeed Logs.');
                foreach (Logger::getMessages() as $message) {
                    $this->getLog()->debug($message);
                }
            }

            // Log and return empty?
            $this->getLog()->error('Unable to parse feed: ' . $picoFeedException->getMessage());
            $this->getLog()->debug($picoFeedException->getTraceAsString());
        }

        // Output any PicoFeed logs
        if ($picoFeedLoggingEnabled) {
            foreach (Logger::getMessages() as $message) {
                $this->getLog()->debug($message);
            }
        }

        return $this;
    }

    public function fetchDuration(DurationProviderInterface $durationProvider): WidgetProviderInterface
    {
        // Duration can depend on the number of items per page for some widgets
        // this is a legacy way of working, and our preference is to use elements
        $numItems = $durationProvider->getProperty('numItems', 0);

        if ($durationProvider->getProperty('durationIsPerItem', 0) == 1 && $numItems > 1) {
            // If we have paging involved then work out the page count.
            $itemsPerPage = $durationProvider->getProperty('itemsPerPage', 0);
            if ($itemsPerPage > 0) {
                $numItems = ceil($numItems / $itemsPerPage);
            }

            $durationProvider->setDuration($durationProvider->getDuration() / $numItems);
        }

        return $this;
    }

    public function getDataCacheKey(DataProviderInterface $dataProvider): ?string
    {
        // No special cache key requirements.
        return null;
    }
}

<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class AnalyticsController extends BaseController
{
    use ResponseTrait;

    protected $apiToken;
    protected $zoneId;

    public function __construct()
    {
        $this->apiToken = getenv('CLOUDFLARE_API_TOKEN');
        $this->zoneId = getenv('CLOUDFLARE_ZONE_ID');
    }

    public function index()
    {
        $data = [
            'title' => 'Web Analytics',
            'hasConfig' => (!empty($this->apiToken) && !empty($this->zoneId))
        ];

        return view('admin/analytics', $data);
    }

    public function schema()
    {
        $query = 'query {
          __type(name: "httpRequests1dGroupsSum") {
            name
            fields {
              name
            }
          }
        }';
        
        $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        return $this->response->setJSON(json_decode($response));
    }

    public function getData()
    {
        if (empty($this->apiToken) || empty($this->zoneId)) {
            return $this->fail('Konfigurasi Cloudflare belum diatur di .env', 400);
        }

        $period = $this->request->getGet('period') ?? '24h';
        
        if ($period === '7d') {
            $startTime = gmdate('Y-m-d', strtotime('-7 days'));
            $endTime = gmdate('Y-m-d');
            $nodeName = 'httpRequests1dGroups';
            $dateDimension = 'date';
            $orderBy = 'date_ASC';
            $timeFilter = 'date_geq: $start, date_leq: $end';
        } else if ($period === '30d') {
            $startTime = gmdate('Y-m-d', strtotime('-30 days'));
            $endTime = gmdate('Y-m-d');
            $nodeName = 'httpRequests1dGroups';
            $dateDimension = 'date';
            $orderBy = 'date_ASC';
            $timeFilter = 'date_geq: $start, date_leq: $end';
        } else {
            // default 24h
            $startTime = gmdate('Y-m-d\TH:i:s\Z', strtotime('-24 hours'));
            $endTime = gmdate('Y-m-d\TH:i:s\Z');
            $nodeName = 'httpRequests1hGroups';
            $dateDimension = 'datetime';
            $orderBy = 'datetime_ASC';
            $timeFilter = 'datetime_geq: $start, datetime_leq: $end';
        }

        $cacheKey = "cf_analytics_{$this->zoneId}_{$period}";
        $cache = \Config\Services::cache();
        
        // Cache data for 5 minutes
        if ($cachedData = $cache->get($cacheKey)) {
            return $this->respond($cachedData);
        }

        $query = 'query ($zoneTag: string, $start: string, $end: string) {
            viewer {
                zones(filter: { zoneTag: $zoneTag }) {
                    ' . $nodeName . '(
                        filter: { ' . $timeFilter . ' }
                        limit: 1000
                        orderBy: [' . $orderBy . ']
                    ) {
                        dimensions { ' . $dateDimension . ' }
                        sum {
                            requests
                            bytes
                            pageViews
                            threats
                        }
                    }
                }
            }
        }';

        $variables = [
            'zoneTag' => $this->zoneId,
            'start' => $startTime,
            'end' => $endTime
        ];

        $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'query' => $query,
            'variables' => $variables
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$response) {
            return $this->fail('Gagal mengambil data dari Cloudflare. Pastikan Token dan Zone ID benar.', 500);
        }

        $result = json_decode($response, true);
        
        if (isset($result['errors'])) {
            return $this->fail($result['errors'][0]['message'] ?? 'GraphQL Error', 500);
        }

        $nodes = $result['data']['viewer']['zones'][0][$nodeName] ?? [];
        
        // Parse and format data for Chart.js
        $labels = [];
        $requestsData = [];
        $threatsData = [];
        $pageViewsData = [];
        $bytesTotal = 0;
        
        $totals = [
            'requests' => 0,
            'pageViews' => 0,
            'threats' => 0,
            'bytes' => 0,
            'errors' => 0 // Edge response errors removed as they are dimensions, not sums
        ];

        foreach ($nodes as $node) {
            // Format label depending on period
            if ($period === '24h') {
                $labels[] = date('H:i', strtotime($node['dimensions'][$dateDimension]));
            } else {
                $labels[] = date('d M', strtotime($node['dimensions'][$dateDimension]));
            }
            
            $requestsData[] = $node['sum']['requests'];
            $threatsData[] = $node['sum']['threats'] ?? 0;
            $pageViewsData[] = $node['sum']['pageViews'] ?? 0;
            
            $totals['requests'] += $node['sum']['requests'];
            $totals['threats'] += $node['sum']['threats'] ?? 0;
            $totals['pageViews'] += $node['sum']['pageViews'] ?? 0;
            $totals['bytes'] += $node['sum']['bytes'];
        }

        // Format bytes to MB/GB
        if ($totals['bytes'] > 1073741824) {
            $totals['bytesFormatted'] = round($totals['bytes'] / 1073741824, 2) . ' GB';
        } else {
            $totals['bytesFormatted'] = round($totals['bytes'] / 1048576, 2) . ' MB';
        }

        $responseData = [
            'chart' => [
                'labels' => $labels,
                'requests' => $requestsData,
                'pageViews' => $pageViewsData,
                'threats' => $threatsData
            ],
            'totals' => $totals
        ];

        // Save to cache for 300 seconds (5 mins)
        $cache->save($cacheKey, $responseData, 300);

        return $this->respond($responseData);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestCloudflareDeployment extends Command
{
    protected $signature = 'cloudflare:test-deployment';
    protected $description = 'Create or deploy a page on Cloudflare Pages.';

    protected $apiUrl = 'https://api.cloudflare.com/client/v4/';
    protected $accountId;
    protected $apiKey;

    public function __construct()
    {
        parent::__construct();
        $this->accountId = '255885cab86645396f08b88bb4c683f2'; // Replace with your account ID
        $this->apiKey = env('CLOUDFLARE_API_TOKEN'); // Ensure this is set in your .env
    }

    public function handle()
    {
        $this->info('Starting Cloudflare deployment test...');
        $projectName = 'nextjs-blog'; // Update with your valid project name

        // Check if the project already exists
        $existingProject = $this->getExistingProject($projectName);

        if ($existingProject) {
            $this->info("Project '{$projectName}' already exists. Deploying to existing project...");
            $this->deployPage($projectName);
        } else {
            // Create the project since it doesn't exist
            $this->info('Creating Cloudflare Page...');
            $createResponse = $this->createPageProject($this->getProjectData($projectName));

            if (!$createResponse['success']) {
                $this->error('Failed to create page: ' . $createResponse['errors'][0]['message']);
                return;
            }

            $this->info('Page created successfully!');

            // Deploying code to the new project
            $this->deployPage($projectName);
        }
    }

    private function getExistingProject($projectName)
    {
        $response = $this->makeRequest('GET', "accounts/{$this->accountId}/pages/projects");

        if ($response['success']) {
            foreach ($response['result'] as $project) {
                if ($project['name'] === $projectName) {
                    $this->info('Found existing project: ' . json_encode($project)); // Debug output
                    return $project;
                }
            }
        }

        return null; // Project not found
    }

    private function createPageProject($data)
    {
        return $this->makeRequest('POST', "accounts/{$this->accountId}/pages/projects", json_encode($data));
    }

    private function getProjectData($projectName)
    {
        return [
            'name' => $projectName,
            'production_branch' => 'main', // Specify your production branch
            'build_config' => [
                'build_caching' => true,
                'build_command' => 'npm run build',
                'destination_dir' => 'build',
                'root_dir' => '/',
                'web_analytics_tag' => 'cee1c73f6e4743d0b5e6bb1a0bcaabcc',
                'web_analytics_token' => '021e1057c18547eca7b79f2516f06o7x',
            ],
        ];
    }

    private function deployPage($projectName)
    {
        $this->info("Deploying to project: {$projectName}");
    
        // Define the deployment data. This example assumes you're deploying from the main branch.
        $deploymentData = [
            'branch' => 'main', // Specify the branch to deploy
            //'manifest' => $this->getManifest(), // Include the manifest for deployment
        ];
    
        // Make sure we're using the correct endpoint with the project name
        $deploymentResponse = $this->makeRequest('POST', "accounts/{$this->accountId}/pages/projects/{$projectName}/deployments", json_encode($deploymentData));
    
        if (!$deploymentResponse['success']) {
            // Enhanced error handling for debugging
            $errorMessage = $deploymentResponse['errors'][0]['message'] ?? 'Unknown error';
            $this->error('Failed to deploy page: ' . $errorMessage);
            return;
        }
    
        $this->info('Page deployed successfully!');
    }
    
    private function getManifest()
    {
        // Construct your manifest here. For example, you can define a basic structure like this:
        return [
            'version' => 1, // Version of the manifest
            'files' => [
                // List the files that are part of the deployment
                // Example structure:
                [
                    'path' => 'index.html',
                    'content' => file_get_contents('path/to/your/index.html'), // Adjust the path accordingly
                ],
                // Add more files as needed
            ],
        ];
    }

    private function makeRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ]);

        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedResponse = json_decode($response, true);

        if ($httpCode >= 400) {
            return [
                'success' => false,
                'errors' => $decodedResponse['errors'] ?? [['message' => 'Unknown error']],
            ];
        }

        return $decodedResponse;
    }
}

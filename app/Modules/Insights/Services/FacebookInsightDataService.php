<?php

namespace App\Modules\Insights\Services;

class FacebookInsightDataService
{
    /**
     * Collect randomized mock Facebook insight data.
     *
     * TESTING ONLY: No database queries are executed.
     * Mock metrics are generated consistently per post ID.
     */
    public function collect(
        string $postId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $commentPool = [
            'The municipality is doing good work by improving our roads.',
            'Garbage has not been collected regularly in our neighborhood.',
            'Please install streetlights along the road near our residential area.',
            'The new road has reduced travel time for many residents.',
            'The drainage channels need cleaning before the rainy season.',
            'We need a permanent solution for the recurring water shortage.',
            'Please provide updates about construction projects in our area.',
            'The streets around the market need better waste management.',
            'We appreciate the effort to improve roads in our neighborhood.',
            'Some roads are still difficult to use during heavy rainfall.',
            'Public toilets around busy markets need improvement.',
            'Streetlights are not working in several locations.',
            'We appreciate efforts to keep public spaces clean.',
            'Please repair damaged sidewalks to make walking safer.',
            'Water supply interruptions are affecting local businesses.',
            'Construction materials have been left on the road for days.',
            'The city looks cleaner when waste is collected on time.',
            'Residents need clear information about municipal service schedules.',
            'Floodwater is accumulating because drainage channels are blocked.',
            'Please give attention to roads in the outskirts of the city.',
            'Public transport access would improve with completed road repairs.',
            'The municipality should respond faster to residents’ complaints.',
            'The new public infrastructure is a positive change for our city.',
            'Please improve lighting around schools and public gathering places.',
            'Road construction and drainage work need better coordination.',
            'Water is available only for a few hours in our neighborhood.',
            'The road project has been delayed without a public explanation.',
            'Thank you for responding quickly to our service request.',
            'Market waste is creating an unpleasant smell for nearby residents.',
            'The municipality should publish its project completion dates.',
            'The road repairs are useful, but some sections remain unfinished.',
            'We need better waste collection services in residential areas.',
            'The new drainage system has reduced flooding in our street.',
            'Please improve access to clean water for all neighborhoods.',
            'Public service offices need to respond to requests more efficiently.',
            'The street near the school becomes dangerous at night.',
            'Road maintenance should include smaller streets, not only main roads.',
            'We are happy with the recent improvements to our neighborhood.',
            'Water shortages are affecting schools and health facilities.',
            'Please explain how residents can report municipal service problems.',
        ];

        // Stable random selection per post ID for repeatable testing.
        $seed = (int) sprintf('%u', crc32($postId));
        mt_srand($seed);

        $totalComments = mt_rand(80, 250);
        $analyzedComments = mt_rand(
            (int) ceil($totalComments * 0.65),
            $totalComments
        );

        $sampleSize = min(
            count($commentPool),
            $analyzedComments
        );

        $indexes = array_keys($commentPool);
        shuffle($indexes);

        $sampleComments = [];

        foreach (array_slice($indexes, 0, $sampleSize) as $index) {
            $sampleComments[] = $commentPool[$index];
        }

        $positive = mt_rand(15, 65);
        $neutral = mt_rand(10, 45);
        $negative = 100 - $positive - $neutral;

        // Ensure all three sentiment categories remain valid.
        if ($negative < 5) {
            $negative = 5;
            $positive = mt_rand(15, 65);
            $neutral = 100 - $positive - $negative;
        }

        $sentimentCounts = [
            'positive' => (int) round(
                $analyzedComments * $positive / 100
            ),
            'neutral' => (int) round(
                $analyzedComments * $neutral / 100
            ),
            'negative' => 0,
        ];

        // Adjust the last category so counts sum exactly.
        $sentimentCounts['negative'] = max(
            0,
            $analyzedComments
                - $sentimentCounts['positive']
                - $sentimentCounts['neutral']
        );

        $classifiedTotal = array_sum($sentimentCounts);

        $sentimentPercentages = [];

        foreach ($sentimentCounts as $sentiment => $count) {
            $sentimentPercentages[$sentiment] = $classifiedTotal > 0
                ? round(($count / $classifiedTotal) * 100, 2)
                : 0;
        }

        $topics = [
            'roads_infrastructure',
            'waste_management',
            'water',
            'street_lighting',
            'drainage_and_flooding',
            'other',
        ];

        $topicWeights = [];

        foreach ($topics as $topic) {
            $topicWeights[$topic] = mt_rand(5, 35);
        }

        $topicTotal = array_sum($topicWeights);

        $topicDistribution = [];

        foreach ($topicWeights as $topic => $weight) {
            $topicDistribution[$topic] = round(
                ($weight / $topicTotal) * 100,
                2
            );
        }

        $languages = [
            'am' => mt_rand(35, 65),
            'om' => mt_rand(20, 45),
            'en' => mt_rand(5, 20),
        ];

        $languageTotal = array_sum($languages);
        $languageDistribution = [];

        foreach ($languages as $language => $weight) {
            $languageDistribution[$language] = round(
                ($weight / $languageTotal) * 100,
                2
            );
        }

        return [
            'post_id' => $postId,

            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],

            'metrics' => [
                'total_comments' => $totalComments,
                'analyzed_comments' => $analyzedComments,
                'unanalysed_comments' => max(
                    0,
                    $totalComments - $analyzedComments
                ),
                'sentiment_distribution' => $sentimentCounts,
                'sentiment_percentages' => $sentimentPercentages,
                'topic_distribution' => $topicDistribution,
                'language_distribution' => $languageDistribution,
            ],

            'sample_comments' => $sampleComments,
        ];
    }
}

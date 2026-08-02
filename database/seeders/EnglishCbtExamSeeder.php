<?php

namespace Database\Seeders;

use App\Models\CbtExam;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EnglishCbtExamSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            CbtExam::where('school_id', 68)
                ->where('title', 'like', '%English%')
                ->update(['status' => 'archived', 'ends_at' => now()]);

            $exam = CbtExam::create([
                'school_id' => 68,
                'created_by' => 734,
                'title' => 'English Language CBT',
                'exam_code' => 'ENG-CBT-' . now()->format('ymd-His'),
                'delivery_mode' => 'online',
                'status' => 'published',
                'duration_minutes' => 45,
                'total_marks' => 20,
                'pass_mark' => 50,
                'max_attempts' => 1,
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'show_result_after_submit' => false,
                'access_code_required' => false,
                'access_code' => null,
                'calculator_enabled' => false,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(2),
                'published_at' => now(),
                'general_instructions' => 'Read all instructions carefully before you start. Choose the best answer for each question. Do not copy, paste, right-click, refresh, or leave the exam page during the test. Comprehension questions must be answered from the passage provided. Submit only when you are sure you are done.',
            ]);

            $sectionA = $exam->sections()->create(['title' => 'Objective Questions', 'instructions' => 'Choose the correct option.', 'sort_order' => 1, 'default_marks' => 1]);
            $sectionB = $exam->sections()->create(['title' => 'True or False', 'instructions' => 'Choose True if the statement is correct, or False if it is wrong.', 'sort_order' => 2, 'default_marks' => 1]);
            $sectionC = $exam->sections()->create(['title' => 'Comprehension', 'instructions' => 'Read the passage and answer the questions that follow.', 'sort_order' => 3, 'default_marks' => 1]);

            $addQuestion = function ($section, string $type, string $text, string $correct, array $options, int $sort, $group = null, ?string $instructions = null) use ($exam): void {
                $question = $exam->questions()->create([
                    'section_id' => $section->id,
                    'question_group_id' => $group?->id,
                    'question_type' => $type,
                    'question_text' => $text,
                    'instructions' => $instructions,
                    'marks' => 1,
                    'sort_order' => $sort,
                    'difficulty' => 'standard',
                ]);

                foreach ($options as $index => $optionText) {
                    $label = chr(65 + $index);
                    $question->options()->create([
                        'label' => $label,
                        'option_text' => $optionText,
                        'is_correct' => $label === $correct,
                        'sort_order' => $index + 1,
                    ]);
                }
            };

            $addQuestion($sectionA, 'single_choice', 'Choose the word that is nearest in meaning to "brave".', 'B', ['Weak', 'Courageous', 'Careless', 'Quiet'], 1);
            $addQuestion($sectionA, 'single_choice', 'Which of these is a noun?', 'C', ['Quickly', 'Beautiful', 'School', 'Run'], 2);
            $addQuestion($sectionA, 'single_choice', 'Select the correctly punctuated sentence.', 'A', ['Where are you going?', 'Where are you going.', 'Where are you going!', 'Where are you going,'], 3);
            $addQuestion($sectionA, 'single_choice', 'The plural form of "child" is _____.', 'D', ['childs', 'childes', 'childrens', 'children'], 4);
            $addQuestion($sectionA, 'single_choice', 'Identify the verb in this sentence: The pupils wrote neatly.', 'B', ['pupils', 'wrote', 'neatly', 'the'], 5);
            $addQuestion($sectionA, 'single_choice', 'Choose the correct article: She bought _____ umbrella.', 'A', ['an', 'a', 'the', 'no article'], 6);
            $addQuestion($sectionA, 'single_choice', 'Which word is opposite in meaning to "ancient"?', 'C', ['Old', 'Former', 'Modern', 'Historic'], 7);
            $addQuestion($sectionA, 'single_choice', 'A sentence that asks a question is called _____.', 'B', ['declarative', 'interrogative', 'imperative', 'exclamatory'], 8);
            $addQuestion($sectionA, 'single_choice', 'Choose the correct spelling.', 'D', ['Recieve', 'Beleive', 'Acheive', 'Receive'], 9);
            $addQuestion($sectionA, 'single_choice', 'The word "slowly" is an example of _____.', 'A', ['an adverb', 'a pronoun', 'a conjunction', 'an adjective'], 10);

            $addQuestion($sectionB, 'true_false', 'A pronoun can be used in place of a noun.', 'A', ['True', 'False'], 11);
            $addQuestion($sectionB, 'true_false', 'Every sentence must begin with a capital letter.', 'A', ['True', 'False'], 12);
            $addQuestion($sectionB, 'true_false', 'The past tense of "go" is "goed".', 'B', ['True', 'False'], 13);
            $addQuestion($sectionB, 'true_false', 'An adjective describes a noun.', 'A', ['True', 'False'], 14);
            $addQuestion($sectionB, 'true_false', 'A comma is normally used to end a sentence.', 'B', ['True', 'False'], 15);

            $group = $exam->questionGroups()->create([
                'section_id' => $sectionC->id,
                'group_type' => 'comprehension',
                'title' => 'Comprehension Passage',
                'instructions' => 'Read the passage carefully and answer questions 16 to 20.',
                'passage' => 'Amaka loved visiting her grandmother during the long holiday. Every morning, they walked to the small farm behind the house to water the vegetables. Her grandmother taught her that patience and care were important in farming. At first, Amaka wanted the crops to grow quickly, but she soon understood that good things often take time. By the end of the holiday, she had learnt how to plant, water, weed, and harvest fresh tomatoes.',
                'sort_order' => 16,
            ]);

            $addQuestion($sectionC, 'single_choice', 'Where did Amaka visit during the long holiday?', 'C', ['Her uncle', 'Her school', 'Her grandmother', 'Her friend'], 16, $group);
            $addQuestion($sectionC, 'single_choice', 'What did Amaka and her grandmother water every morning?', 'A', ['Vegetables', 'Flowers', 'Rice', 'Trees'], 17, $group);
            $addQuestion($sectionC, 'single_choice', 'According to the passage, what qualities are important in farming?', 'B', ['Speed and noise', 'Patience and care', 'Money and pride', 'Strength and anger'], 18, $group);
            $addQuestion($sectionC, 'single_choice', 'What crop was mentioned in the passage?', 'D', ['Yam', 'Beans', 'Maize', 'Tomatoes'], 19, $group);
            $addQuestion($sectionC, 'single_choice', 'The best title for the passage is _____.', 'A', ['Amaka Learns Farming', 'The School Holiday', 'A Walk to Town', 'Selling Tomatoes'], 20, $group);

            $exam->update(['total_marks' => $exam->questions()->sum('marks')]);
        });
    }
}

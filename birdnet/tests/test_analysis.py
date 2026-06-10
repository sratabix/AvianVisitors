import os
import unittest
from unittest.mock import patch

from tests.helpers import TESTDATA, Settings
from utils.analysis import filter_humans, run_analysis
from utils.classes import ParseFileName


class TestRunAnalysis(unittest.TestCase):
    def setUp(self):
        source = os.path.join(TESTDATA, "Pica pica_30s.wav")
        self.test_file = os.path.join(TESTDATA, "2024-02-24-birdnet-16:19:37.wav")
        if os.path.exists(self.test_file):
            os.unlink(self.test_file)
        os.symlink(source, self.test_file)

    def tearDown(self):
        if os.path.exists(self.test_file):
            os.unlink(self.test_file)

    @patch("utils.helpers._load_settings")
    @patch("utils.analysis.loadCustomSpeciesList")
    def test_run_analysis(self, mock_loadCustomSpeciesList, mock_load_settings):

        mock_load_settings.return_value = Settings.with_defaults()
        mock_loadCustomSpeciesList.return_value = []

        test_file = ParseFileName(self.test_file)

        expected_results = [
            {"confidence": 0.912, "sci_name": "Pica pica"},
            {"confidence": 0.9316, "sci_name": "Pica pica"},
            {"confidence": 0.8857, "sci_name": "Pica pica"},
        ]

        detections = run_analysis(test_file)

        self.assertEqual(len(detections), len(expected_results))
        for det, expected in zip(detections, expected_results):
            self.assertAlmostEqual(det.confidence, expected["confidence"], delta=1e-4)
            self.assertEqual(det.scientific_name, expected["sci_name"])


class TestFilterHumans(unittest.TestCase):
    @patch("utils.helpers._load_settings")
    def test_filter_humans_no_human(self, mock_load_settings):
        mock_load_settings.return_value = Settings.with_defaults()

        detections = [[("Bird_A", 0.9), ("Bird_B", 0.8)], [("Bird_C", 0.7), ("Bird_D", 0.6)]]

        expected = [[("Bird_A", 0.9), ("Bird_B", 0.8)], [("Bird_C", 0.7), ("Bird_D", 0.6)]]

        result = filter_humans(detections)

        self.assertEqual(result, expected)

    @patch("utils.helpers._load_settings")
    def test_filter_empty(self, mock_load_settings):
        mock_load_settings.return_value = Settings.with_defaults()

        detections = []

        expected = []

        result = filter_humans(detections)

        self.assertEqual(result, expected)

    @patch("utils.helpers._load_settings")
    def test_filter_humans_with_human(self, mock_load_settings):
        mock_load_settings.return_value = Settings.with_defaults()

        detections = [
            [("Human_Human", 0.95), ("Bird_A", 0.8)],
            [("Bird_A", 0.9), ("Bird_B", 0.8)],
            [("Bird_C", 0.9), ("Bird_D", 0.8)],
            [("Bird_B", 0.7), ("Human vocal_Human vocal", 0.9)],
        ]

        expected = [[("Human_Human", 0.0)], [("Human_Human", 0.0)], [("Human_Human", 0.0)], [("Human_Human", 0.0)]]

        result = filter_humans(detections)

        self.assertEqual(result, expected)

    @patch("utils.helpers._load_settings")
    def test_filter_humans_with_human_neighbour(self, mock_load_settings):
        mock_load_settings.return_value = Settings.with_defaults()

        detections = [
            [("Bird_A", 0.9), ("Bird_B", 0.8)],
            [("Bird_D", 0.9), ("Bird_E", 0.8)],
            [("Human_Human", 0.95), ("Bird_C", 0.7)],
            [("Bird_F", 0.6), ("Bird_G", 0.5)],
        ]

        expected = [[("Bird_A", 0.9), ("Bird_B", 0.8)], [("Human_Human", 0.0)], [("Human_Human", 0.0)], [("Human_Human", 0.0)]]

        result = filter_humans(detections)

        self.assertEqual(result, expected)

    @patch("utils.helpers._load_settings")
    def test_filter_humans_with_deep_human(self, mock_load_settings):
        mock_load_settings.return_value = Settings.with_defaults()

        detections = [
            [("Bird_A", 0.9), ("Bird_B", 0.8)],
            [("Bird_D", 0.9), ("Bird_E", 0.8)],
            [("Bird_C", 0.7)] * 10 + [("Human_Human", 0.95)],
            [("Bird_F", 0.6), ("Bird_G", 0.5)],
        ]

        expected = [[("Bird_A", 0.9), ("Bird_B", 0.8)], [("Bird_D", 0.9), ("Bird_E", 0.8)], [("Bird_C", 0.7)] * 10, [("Bird_F", 0.6), ("Bird_G", 0.5)]]

        result = filter_humans(detections)

        self.assertEqual(result, expected)

    @patch("utils.helpers._load_settings")
    def test_filter_humans_with_human_deep(self, mock_load_settings):
        settings = Settings.with_defaults()
        settings["PRIVACY_THRESHOLD"] = 1
        mock_load_settings.return_value = settings

        detections = [
            [("Bird_A", 0.9), ("Bird_B", 0.8)],
            [("Bird_D", 0.9), ("Bird_E", 0.8)],
            [("Bird_C", 0.7)] * 10 + [("Human_Human", 0.95)],
            [("Bird_F", 0.6), ("Bird_G", 0.5)],
        ]

        expected = [[("Bird_A", 0.9), ("Bird_B", 0.8)], [("Human_Human", 0.0)], [("Human_Human", 0.0)], [("Human_Human", 0.0)]]

        result = filter_humans(detections)

        self.assertEqual(result, expected)


if __name__ == "__main__":
    unittest.main()

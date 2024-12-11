import unittest
from src.gstraccini_bot.commands import batch_copy_workflow_command

class TestBatchCopyWorkflowCommand(unittest.TestCase):
    def test_workflow_file_exists(self):
        result = batch_copy_workflow_command("example.yml", [])
        self.assertNotEqual(result, "Error: Workflow file does not exist.")

    def test_workflow_file_does_not_exist(self):
        result = batch_copy_workflow_command("nonexistent.yml", [])
        self.assertEqual(result, "Error: Workflow file does not exist.")

    def test_filter_repositories(self):
        result = batch_copy_workflow_command("example.yml", ["language:python"])
        self.assertIsNotNone(result)

if __name__ == '__main__':
    unittest.main()

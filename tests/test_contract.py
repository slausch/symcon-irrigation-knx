import json
import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE = ROOT / "IrrigationKNX" / "module.php"


class ModuleContractTest(unittest.TestCase):
    def test_all_json_files_are_valid(self):
        for path in [
            ROOT / "library.json",
            ROOT / "IrrigationKNX" / "module.json",
            ROOT / "IrrigationKNX" / "form.json",
            ROOT / "IrrigationKNX" / "locale.json",
        ]:
            with self.subTest(path=path):
                json.loads(path.read_text(encoding="utf-8"))

    def test_form_defines_exactly_ten_zone_rows_and_two_main_valves(self):
        form = json.loads((ROOT / "IrrigationKNX" / "form.json").read_text(encoding="utf-8"))
        lists = {}

        def visit(value):
            if isinstance(value, dict):
                if value.get("type") == "List":
                    lists[value["name"]] = value
                for child in value.values():
                    visit(child)
            elif isinstance(value, list):
                for child in value:
                    visit(child)

        visit(form)
        self.assertEqual(2, len(lists["MainValves"]["values"]))
        self.assertEqual(10, len(lists["Zones"]["values"]))
        self.assertEqual(10, lists["Zones"]["rowCount"])
        for table in lists.values():
            for column in table["columns"]:
                self.assertRegex(column["width"], r"^\d+px$")

    def test_hardware_write_is_centralized_and_uses_request_action(self):
        source = MODULE.read_text(encoding="utf-8")
        calls = re.findall(r"(?<!function )RequestAction\s*\(", source)
        self.assertEqual(1, len(calls))
        self.assertNotIn("HM_WriteValue", source)
        self.assertNotIn("EIB_Switch", source)
        self.assertIn("RequestAction($variableID, $state);", source)

    def test_state_machine_has_all_safety_paths(self):
        source = MODULE.read_text(encoding="utf-8")
        for marker in [
            "maximum program runtime exceeded",
            "valve feedback mismatch",
            "rain or soil moisture limit reached",
            "Every configured zone is closed",
            "Recovered interrupted run",
            "one or more valves did not confirm the closed state",
        ]:
            with self.subTest(marker=marker):
                self.assertIn(marker, source)


if __name__ == "__main__":
    unittest.main()

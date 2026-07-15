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

        actions = form["actions"][1]["items"]
        self.assertTrue(any(item.get("caption") == "Pause / Resume" for item in actions))
        self.assertTrue(any(item.get("caption") == "Skip current zone" for item in actions))

        header = form["elements"][0]["items"]
        simulation_row = form["elements"][1]["items"]
        self.assertEqual("README", header[0]["caption"])
        self.assertNotIn("Simulation", [item.get("name") for item in header])
        self.assertEqual(["Simulation", "SimulationRuntimeMinutes"], [item.get("name") for item in simulation_row])

    def test_visible_name_changes_without_changing_module_identity(self):
        library = json.loads((ROOT / "library.json").read_text(encoding="utf-8"))
        module = json.loads((ROOT / "IrrigationKNX" / "module.json").read_text(encoding="utf-8"))
        self.assertEqual("Wangari Irrigation", library["name"])
        self.assertEqual("Wangari Irrigation", module["name"])
        self.assertIn("class WangariIrrigation extends IPSModule", MODULE.read_text(encoding="utf-8"))
        self.assertEqual("{CC602ACD-2B7C-46A5-97A5-E76D078A61B9}", library["id"])
        self.assertEqual("{EA07A4D2-C085-46AC-8901-6A5B52E34B90}", module["id"])
        self.assertTrue((ROOT / "LICENSE").exists())

    def test_hardware_write_is_centralized_and_uses_request_action(self):
        source = MODULE.read_text(encoding="utf-8")
        calls = re.findall(r"(?<!function )RequestAction\s*\(", source)
        self.assertEqual(1, len(calls))
        self.assertNotIn("HM_WriteValue", source)
        self.assertNotIn("EIB_Switch", source)
        self.assertNotIn("IPS_LogMessage", source)
        self.assertIn("RequestAction($variableID, $state);", source)

    def test_zone_progress_is_instance_local_and_registered_for_all_zones(self):
        source = MODULE.read_text(encoding="utf-8")
        self.assertIn("'Zone' . $zone . 'Progress'", source)
        self.assertIn("CurrentZoneTotalSeconds", source)
        self.assertIn("clearAllZoneProgress", source)

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

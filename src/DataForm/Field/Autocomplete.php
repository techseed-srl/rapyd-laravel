<?php

namespace Zofe\Rapyd\DataForm\Field;

use Collective\Html\FormFacade as Form;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use MyProject\Proxies\__CG__\stdClass;
use Zofe\Rapyd\Rapyd;

class Autocomplete extends Field
{
    public $type = "autocomplete";
    public $css_class = "form-control autocompleter typeahead";

    public $remote;

    public $local_options;

    public $record_id;
    public $record_label;

    public $must_match = false;
    public $auto_fill = false;
    public $parent_id = '';

    public $min_chars = '2';
    public $limit = 5;
    public $clause = "where";
    public $is_local;
    public $description;
    public $change;

    //getvalue quando è local

    public function options($options)
    {
        $this->is_local = true;
        parent::options($options);
        foreach ($options as $key=>$value) {
            $row = new \stdClass();
            $row->key = $key;
            $row->value = $value;
            $this->local_options[] =$row;
        }

        return $this;

    }

    public function change($callback)
    {
        $this->change = $callback;
        return $this;
    }
    
    public function minChars($len)
    {
        $this->min_chars = $len;
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function mustMatch($bool)
    {
        $this->must_match = $bool;
        return $this;
    }

    public function getValue()
    {
        if (!$this->is_local && !$this->record_label && $this->rel_field != "") {
            $this->remote($this->rel_field, trim(strstr($this->rel_key,'.'),'.'));
        }

        parent::getValue();
        if (count($this->local_options)) {
            foreach ($this->options as $value => $description) {
                if ($this->value == $value) {
                    $this->description = $description;
                }
            }
        }
    }

    public function remote($record_label = null, $record_id = null, $remote = null)
    {
        $this->record_label = ($record_label!="") ? $record_label : $this->db_name ;
        $this->record_id = ($record_id!="") ? $record_id : preg_replace('#([a-z0-9_-]+\.)?(.*)#i','$2',$this->rel_key);
        if ($remote!="") {
            $this->remote = $remote;
            if (is_array($record_label)) {
                $this->record_label = current($record_label);
            }
            if ($this->rel_field!= "") {
                $this->record_label = $this->rel_field;
            }
        } else {

            $data["entity"] = get_class($this->relation->getRelated());
            $data["field"]  = $record_label;
            if (is_array($record_label)) {
                $this->record_label = $this->rel_field;
            }
            $hash = substr(md5(serialize($data)), 0, 12);
            Session::put($hash, $data);

            $this->remote = route('rapyd.remote', array('hash'=> $hash));
        }

        return $this;
    }

    public function search($record_label, $record_id = null)
    {
        $record_id = ($record_id!="") ? $record_id :  preg_replace('#([a-z0-9_-]+\.)?(.*)#i','$2',$this->rel_key);
        $this->remote($record_label, $record_id);

        return $this;
    }

    public function build()
    {
        $output = "";
        Rapyd::css('autocomplete/autocomplete.css');
        Rapyd::js('autocomplete/typeahead.bundle.min.js');
        Rapyd::js('template/handlebars.js');

        unset($this->attributes['type']);

        if (parent::build() === false) return;

        switch ($this->status) {
            case "disabled":
            case "show":
                if ( (!isset($this->value)) ) {
                    $output = $this->layout['null_label'];
                } elseif ($this->value == "") {
                    $output = "";
                } else {
                    if ($this->relation != null) {
                        $name = $this->rel_field;
                        $value = @$this->relation->get()->first()->$name;
                    } else {
                        $value = $this->value;
                    }
                    $output = nl2br(htmlspecialchars($value));
                }
                $output = "<div class='help-block'>".$output."&nbsp;</div>";
                break;

            case "create":
            case "modify":

                if (\Request::input("auto_".$this->name)) {
                    $autocomplete = \Request::input("auto_".$this->name);
                } elseif ($this->relation != null) {
                    $name = $this->rel_field;
                    $autocomplete = @$this->relation->get()->first()->$name;
                } elseif (count($this->local_options)) {

                    $autocomplete = $this->description;
                } elseif ($this->description!=''){
                    $autocomplete = $this->description;
                } else {
                    $autocomplete = $this->value;
                }

                $output  =  Form::text("auto_".$this->name, $autocomplete, array_merge($this->attributes, array('id'=>"auto_".$this->name)))."\n";
                $output .=  Form::hidden($this->name, $this->value, array('id'=>$this->name));
                $output  =  '<span id="th_'.$this->name.'">'.$output.'</span>';

                if ($this->remote) {
                    $script = <<<acp

                    var blod_{$this->name} = new Bloodhound({
                        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('auto_{$this->name}'),
                        queryTokenizer: Bloodhound.tokenizers.whitespace,
                        remote: {
                            url: '{$this->remote}?q=%QUERY',
                            ajax: {
                                complete: function(response){
                                    response.responseJSON.forEach(function (item) {
                                        blod_{$this->name}.valueCache[item.{$this->record_label}] = item.{$this->record_id};
                                    });
                                }
                            }
                        },
                        limit: {$this->limit}
                    });
                    blod_{$this->name}.valueCache = {};
                    blod_{$this->name}.initialize();

                    $('#th_{$this->name} .typeahead').typeahead({
                        highlight: true,
                        minLength: {$this->min_chars},
                        limit: {$this->limit}
                    }, {
                        displayKey: '{$this->record_label}',
                        name: '{$this->name}',
                        source: blod_{$this->name}.ttAdapter(),
                        templates: {
                            suggestion: Handlebars.compile('{{{$this->record_label}}}')
                        }
                    }).on("typeahead:selected typeahead:autocompleted",
                        function (e,data) {
                            $('#{$this->name}').val(data.{$this->record_id});
                            {$this->change}
                            
                    }).on("typeahead:closed,typeahead:change",
                        function (e,data) {
                            if ('{$this->must_match}') {
                                var _label = $.trim($(this).val());
                                if ( !(_label in blod_{$this->name}.valueCache) ) {
                                    $('#{$this->name}').val('');
                                    $(this).val('');
                                } else {
                                    //Fill data to hidden input, when direct copy data to input without choose from auto-complete results.
                                    $('#{$this->name}').val(blod_{$this->name}.valueCache[_label]);
                                }
                            } else {
                                if ($(this).val() == '') {
                                    $('#{$this->name}').val('');
                                }
                            }
                    });
                    $('#th_{$this->name} .typeahead').keypress(function (e) {
                        if (e.which == 13) {
                            e.preventDefault();
                        }
                    });
                    
                    
acp;

                    Rapyd::script($script);

                } elseif (count($this->options)) {

                    $options = json_encode($this->local_options);

                    //options
                    $script = <<<acp

                    var {$this->name}_options = {$options};
                    var blod_{$this->name} = new Bloodhound({
                        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
                        queryTokenizer: Bloodhound.tokenizers.whitespace,
                        local: {$this->name}_options
                    });


                    blod_{$this->name}.initialize();

                    $('#th_{$this->name} .typeahead').typeahead({
                         hint: true,
                         highlight: true,
                         minLength: {$this->min_chars},
                         limit: {$this->limit}
                    },
                    {
                        name: '{$this->name}',
                        displayKey: 'value',
                        source: blod_{$this->name}.ttAdapter()
                    }).on("typeahead:selected typeahead:autocompleted",
                        function (e,data) {
                            $('#{$this->name}').val(data.key);
                    }).on("typeahead:closed",
                        function (e,data) {
                            if ($(this).val() == '') {
                                $('#{$this->name}').val('');
                            }
                    });
acp;

                    Rapyd::script($script);
                }

                break;

            case "hidden":
                $output = Form::hidden($this->db_name, $this->value);
                break;

            default:;
        }

        $this->output = "\n".$output."\n". $this->extra_output."\n";
    }

}
